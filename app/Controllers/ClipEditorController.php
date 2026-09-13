<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Exceptions\ClipRenderValidationException;
use App\Media\ClipRenderReceipt;
use App\Media\Editor\EditorOptions;
use App\Media\Reframe\ReframePlan;
use App\Media\Reframe\ReframeSubmission;
use App\Queue\ProcessingErrorCatalog;
use App\Media\Subtitles\SrtCodec;
use App\Media\Subtitles\Transcript;
use App\Services\ClipEditService;
use InvalidArgumentException;

final class ClipEditorController
{
    private const FIELDS = ['request_key','start_time','end_time','aspect_ratio','reframe_mode','focus_x','focus_y',
        'reframe_keyframes','transcript_mode','srt','srt_interval_confirmed','style','position','color','accent_color','font_size','title','watermark',
        'font_family','background_color','outline_width','shadow_depth','font_weight','animation','cta_text','logo_asset_id','logo_position','logo_scale'];
    private const SERVER_FIELDS = ['detector_version','output_width','output_height','filtergraph','ffmpeg_args'];
    private $owned;
    private $snapshot;
    private $requestVersion;
    private $user;
    private $consent;
    private $limit;
    private $reframeProfile;
    private ErrorHandler $errors;

    /** Database adapters stay lazy; all clip reads must enforce owner and latest analysis. */
    public function __construct(private View $view, callable $owned, callable $snapshot,
        ClipEditService|callable $requestVersion, ?callable $user=null, ?callable $consent=null,
        ?callable $limit=null, ?callable $reframeProfile=null, private array $config=[])
    {
        $this->owned=$owned;
        $this->snapshot=$snapshot;
        $this->requestVersion=$requestVersion instanceof ClipEditService ? [$requestVersion,'request'] : $requestVersion;
        $this->user=$user ?? static fn (): ?array => null;
        $this->consent=$consent ?? static fn (): bool => false;
        $this->limit=$limit ?? static fn (): bool => false;
        $this->reframeProfile=$reframeProfile ?? static fn (): ?ReframePlan => null;
        $this->errors=new ErrorHandler();
    }

    public function show(Request $request, array $parameters): Response
    {
        $userId=(int)Session::get('user_id',0);
        if ($userId<1) return $this->private(Response::redirect('/login'));
        $clip=$this->find($parameters,$userId);
        if ($clip===null) return $this->notFound();
        return $this->page($clip,$userId);
    }

    public function subtitles(Request $request, array $parameters): Response
    {
        $userId=(int)Session::get('user_id',0);
        if ($userId<1) return $this->private(Response::redirect('/login'));
        $clip=$this->find($parameters,$userId);
        if ($clip===null) return $this->notFound();
        $snapshot=($this->snapshot)((int)$clip['id'],(int)$clip['render_revision']);
        if (($snapshot['track_status'] ?? null)!=='ready' || !($snapshot['transcript'] ?? null) instanceof Transcript) return $this->notFound();
        return $this->private((new Response(SrtCodec::format($snapshot['transcript'])))
            ->withHeader('Content-Type','application/x-subrip; charset=UTF-8')
            ->withHeader('Content-Disposition','attachment; filename="clipe-'.(int)$clip['id'].'.srt"')
            ->withHeader('X-Content-Type-Options','nosniff'));
    }

    public function store(Request $request, array $parameters): Response
    {
        $userId=(int)Session::get('user_id',0);
        if ($userId<1) return $this->private(Response::redirect('/login'));
        $clip=$this->find($parameters,$userId);
        if ($clip===null) return $this->notFound();
        $input=[];
        $errors=[];
        $defaults=EditorOptions::defaults();
        foreach (array_unique(array_merge(self::FIELDS,array_keys($defaults))) as $field) {
            $value=$request->input($field,(string)($defaults[$field] ?? ''));
            $maximum=$field==='srt' ? 262144 : ($field==='reframe_keyframes' ? 16384 : 1024);
            if (!is_string($value) || strlen($value)>$maximum || !mb_check_encoding($value,'UTF-8')) {
                $errors[$field==='srt' ? 'subtitles' : ($field==='reframe_keyframes' ? 'reframe' : $field)]='Revise este campo: valor inválido ou muito longo.';
                $value='';
            }
            $input[$field]=$value;
        }
        foreach (self::SERVER_FIELDS as $field) {
            if ($request->hasInput($field)) $errors['reframe']='Envie somente os controles de enquadramento exibidos no editor.';
        }
        if (preg_match('/^[a-f0-9]{64}$/D',$input['request_key'])!==1) {
            $errors['editor']='A sessão de edição precisa ser atualizada. Revise e envie novamente.';
            $input['request_key']=bin2hex(random_bytes(32));
        }
        if ($input['reframe_mode']==='auto' && !($this->consent)($userId)) {
            $errors['reframe']='Ative o consentimento de enquadramento inteligente ou escolha foco manual.';
        }
        $changedInterval=(float)$input['start_time']!==(float)($clip['render_start_time'] ?? $clip['start_time'])
            || (float)$input['end_time']!==(float)($clip['render_end_time'] ?? $clip['end_time']);
        if ($input['transcript_mode']==='manual' && $changedInterval && $input['srt_interval_confirmed']!=='1') {
            $previous=($this->snapshot)((int)$clip['id'],(int)$clip['render_revision']);
            if (($previous['transcript'] ?? null) instanceof Transcript) {
                $errors['subtitles']='O intervalo mudou. Revise o SRT e confirme seus tempos, ou gere uma nova transcrição automática.';
            }
        }
        if ($errors!==[]) return $this->page($clip,$userId,$input,$errors,422);

        $optionData=array_intersect_key($input,$defaults);
        try { EditorOptions::fromForm(['font_size'=>$input['font_size']]); }
        catch (InvalidArgumentException) {
            return $this->page($clip,$userId,$input,['font_size'=>'Use um tamanho inteiro entre 18 e 96.'],422);
        }
        try {
            $options=EditorOptions::fromForm($optionData);
        } catch (InvalidArgumentException) {
            return $this->page($clip,$userId,$input,['options'=>'Revise estilo, posição, cores, tamanho, título e marca textual.'],422);
        }
        // A complete HTML form sends inactive focus inputs too. Project them by selected mode.
        $aspect=$input['aspect_ratio'];
        $mode=$input['reframe_mode'];
        if ($mode==='original' && $aspect!=='original') $mode='center';
        $reframe=new ReframeSubmission($aspect,$mode,
            $mode==='manual' ? ($input['focus_x']==='' ? '0.5' : $input['focus_x']) : '',
            $mode==='manual' ? ($input['focus_y']==='' ? '0.5' : $input['focus_y']) : '',
            $mode==='auto' ? $input['reframe_keyframes'] : '');
        if (!($this->limit)($userId)) {
            return $this->page($clip,$userId,$input,['editor'=>'Você atingiu o limite de 20 exportações por hora. Aguarde para enviar novamente.'],429)
                ->withHeader('Retry-After','3600');
        }
        try {
            $receipt=($this->requestVersion)((int)$clip['id'],$userId,$input['request_key'],$input['start_time'],$input['end_time'],
                $options,$reframe,$input['transcript_mode'],$input['srt']);
        } catch (ClipRenderValidationException $exception) {
            if ($exception->projectId()!==(int)$clip['project_id']) return $this->notFound();
            return $this->page($clip,$userId,$input,$exception->errors(),422);
        }
        if (!$receipt instanceof ClipRenderReceipt) return $this->notFound();
        Session::flash('clip_editor_feedback',['clip_id'=>$receipt->clipId(),'message'=>$receipt->created()
            ? 'Nova versão enviada para processamento. O clipe anterior foi preservado.'
            : 'Esta versão já foi enviada. Acompanhe o processamento abaixo.']);
        return $this->private(Response::redirect('/clips/'.$receipt->clipId().'/editar'));
    }

    private function find(array $parameters,int $userId): ?array
    {
        $raw=$parameters['id'] ?? '';
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,18}$/D',$raw)!==1 || (string)(int)$raw!==$raw) return null;
        return ($this->owned)((int)$raw,$userId);
    }

    private function page(array $rawClip,int $userId,array $old=[],array $errors=[],int $status=200): Response
    {
        $clip=array_intersect_key($rawClip,array_flip(['id','project_id','title','status','source_duration_seconds','width','height','has_audio','project_name','render_error_code']));
        $id=(int)$clip['id'];
        $revision=(int)$rawClip['render_revision'];
        $snapshot=$revision>0 ? ($this->snapshot)($id,$revision) : null;
        $options=($snapshot['options'] ?? null) instanceof EditorOptions ? $snapshot['options']->toArray() : EditorOptions::fromArray(['style'=>'minimal'])->toArray();
        // Legacy drafts can be reopened, but a new export must use a visible caption style.
        if ($options['style']==='none') $options['style']='minimal';
        $transcript=($snapshot['track_status'] ?? null)==='ready' && ($snapshot['transcript'] ?? null) instanceof Transcript ? $snapshot['transcript'] : null;
        $consent=(bool)($this->consent)($userId);
        $profile=$revision>0 ? ($this->reframeProfile)($id,$revision) : null;
        $reframe=['aspect_ratio'=>'original','reframe_mode'=>'original','focus_x'=>'','focus_y'=>'','reframe_keyframes'=>''];
        if ($profile instanceof ReframePlan) {
            $reframe['aspect_ratio']=$profile->aspectRatio()->value();
            $reframe['reframe_mode']=$profile->mode();
            if ($profile->mode()==='manual') {
                $reframe['focus_x']=$profile->keyframes()[0]->centerXDecimal();
                $reframe['focus_y']=$profile->keyframes()[0]->centerYDecimal();
            } elseif ($profile->mode()==='auto' && $consent) {
                $points=[];
                foreach ($profile->keyframes() as $point) $points[]=['at_ms'=>$point->atMs(),'center_x'=>(float)$point->centerXDecimal(),'center_y'=>(float)$point->centerYDecimal()];
                $reframe['reframe_keyframes']=json_encode($points,JSON_THROW_ON_ERROR);
            } elseif ($profile->mode()==='auto') $reframe['reframe_mode']='center';
        }
        $values=array_replace($options,$reframe,[
            'request_key'=>bin2hex(random_bytes(32)),
            'start_time'=>(string)($rawClip['render_start_time'] ?? $rawClip['start_time']),
            'end_time'=>(string)($rawClip['render_end_time'] ?? $rawClip['end_time']),
            'transcript_mode'=>$transcript!==null ? 'manual' : ((bool)$clip['has_audio'] ? 'auto' : 'manual'),
            'srt'=>$transcript!==null ? SrtCodec::format($transcript) : '',
            'srt_interval_confirmed'=>'',
        ],$old);
        if ($values['style']==='none') $values['style']='minimal';
        if ($values['transcript_mode']==='none') $values['transcript_mode']=(bool)$clip['has_audio'] ? 'auto' : 'manual';
        if ($values['transcript_mode']==='auto' && !(bool)$clip['has_audio']) $values['transcript_mode']='manual';
        if (!$consent && $values['reframe_mode']==='auto') {
            $values['reframe_mode']='center';
            $values['reframe_keyframes']='';
        }
        $user=($this->user)($userId) ?? [];
        $user=array_replace(['id'=>$userId,'name'=>'Conta','email'=>'','credits'=>0,'plan_name'=>'Plano','monthly_minutes'=>0],
            array_intersect_key($user,array_flip(['name','email','credits','plan_name','monthly_minutes'])));
        $feedback=Session::pull('clip_editor_feedback');
        $feedback=is_array($feedback) && ($feedback['clip_id'] ?? null)===$id ? ($feedback['message'] ?? null) : null;
        $code=$clip['render_error_code'] ?? null;
        $renderErrorMessage=is_string($code) ? ProcessingErrorCatalog::message($code) : null;
        if (($clip['status'] ?? null)==='failed' && $renderErrorMessage===null) $renderErrorMessage='Não foi possível concluir esta versão.';
        $response=$this->view->render('clips.editor',[
            'title'=>'Editor de clipe','user'=>$user,'clip'=>$clip,'values'=>$values,'errors'=>$errors,
            'feedback'=>$feedback,'renderErrorMessage'=>$renderErrorMessage,'consentActive'=>$consent,'hasTranscript'=>$transcript!==null,
            'trackStatus'=>$snapshot['track_status'] ?? null,'parentClipId'=>$snapshot['parent_clip_id'] ?? null,
            'previewTranscript'=>$transcript!==null ? $transcript->toArray() : null,
            'renderMaximum'=>max(1,min(180,(int)($this->config['render_max_duration_seconds'] ?? 180))),
            'reframeConfig'=>[
                'duration'=>max(1,min(180,(int)($this->config['reframe_max_duration_seconds'] ?? 90)))*1000,
                'frames'=>max(2,min(180,(int)($this->config['reframe_preview_max_frames'] ?? 180))),
                'edge'=>max(64,min(320,(int)($this->config['reframe_preview_max_edge'] ?? 320))),
            ],
        ]);
        return $this->private(Response::html($response->body(),$status));
    }

    private function private(Response $response): Response { return $response->withHeader('Cache-Control','private, no-store'); }
    private function notFound(): Response { return $this->private($this->errors->renderStatus(404)); }
}
