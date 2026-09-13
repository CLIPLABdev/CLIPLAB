<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Billing\FinancialRepository;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminRepository;
use App\Repositories\SystemLogRepository;
use App\Services\AdminGeminiSettingsService;
use App\Services\AdminService;
use App\Services\PlatformSettingsService;
use App\Services\PlatformBrandAssetService;
use App\Services\PromotionService;
use Throwable;

final class AdminController
{
    private ?\Closure $quotaFactory;

    public function __construct(
        private View $view,
        private AdminRepository $repository,
        private SystemLogRepository $logs,
        private AdminService $service,
        private AdminGeminiSettingsService $gemini,
        private PlatformSettingsService $settings,
        private PromotionService $promotions,
        private ?PlatformBrandAssetService $brandAssets = null,
        private ?FinancialRepository $financial = null,
        ?callable $quotaFactory = null
    ) {
        $this->quotaFactory=$quotaFactory===null?null:\Closure::fromCallable($quotaFactory);
    }

    public function dashboard(Request $request): Response
    {
        return $this->render('admin.dashboard', 'Administração', ['metrics' => $this->repository->dashboard(), 'financial' => $this->financial?->dashboard()]);
    }

    public function users(Request $request): Response
    {
        return $this->render('admin.users', 'Usuários', [
            'mode' => 'users',
            'page' => $this->repository->paginateUsers([
                'q' => $request->query('q', ''),
                'status' => $request->query('status', 'all'),
                'plan' => $request->query('plan', ''),
            ], $this->page($request->query('page', 1))),
            'plans' => $this->repository->plans(true),
        ]);
    }

    /** @param array<string,string> $parameters */
    public function user(Request $request, array $parameters): Response
    {
        $detail = $this->repository->userDetail($this->positiveId($parameters['id'] ?? null));
        if (!is_array($detail)) return Response::text('Não encontrado.', 404);
        $id=(int)$detail['id'];
        return $this->render('admin.user-detail', 'Detalhe do usuário', [
            'user' => $detail, 'plans' => $this->repository->plans(true),
            'financial'=>$this->financial?->dashboard($id),
            'billingHistory'=>$this->financial===null?null:$this->repository->userBillingHistory($id),
            'quota'=>$this->quotaFactory===null?null:($this->quotaFactory)()->snapshotForUser($id),
        ]);
    }

    public function createUser(Request $request): Response
    {
        return $this->mutate('/admin/usuarios', 'Usuário criado.', fn () => $this->service->createUser($this->actorId(), [
            'name'=>$request->input('name',''),'email'=>$request->input('email',''),'password'=>$request->input('password',''),'plan_id'=>$this->positiveId($request->input('plan_id')),'role'=>$request->input('role','user'),'reason'=>$request->input('reason',''),
        ]));
    }

    /** @param array<string,string> $parameters */
    public function editUser(Request $request, array $parameters): Response
    {
        $id=$this->positiveId($parameters['id'] ?? null);
        return $this->mutate('/admin/usuarios/'.$id, 'Usuário atualizado.', fn () => $this->service->editUser($this->actorId(), $id, ['name'=>$request->input('name',''),'email'=>$request->input('email',''),'role'=>$request->input('role',''),'reason'=>$request->input('reason','')]));
    }

    /** @param array<string,string> $parameters */
    public function archiveUser(Request $request, array $parameters): Response
    {
        $id=$this->positiveId($parameters['id']??null); if ($request->input('confirm') !== 'ARQUIVAR') return Response::redirect('/admin/usuarios/'.$id);
        return $this->mutate('/admin/usuarios/'.$id, 'Conta arquivada; histórico preservado.', fn ()=>$this->service->archiveUser($this->actorId(),$id,(string)$request->input('reason','')));
    }

    /** @param array<string,string> $parameters */
    public function restoreUser(Request $request, array $parameters): Response
    {
        $id=$this->positiveId($parameters['id']??null); if ($request->input('confirm') !== 'RESTAURAR') return Response::redirect('/admin/usuarios/'.$id);
        return $this->mutate('/admin/usuarios/'.$id, 'Conta restaurada.', fn ()=>$this->service->restoreUser($this->actorId(),$id,(string)$request->input('reason','')));
    }

    public function projects(Request $request): Response
    {
        return $this->render('admin.projects', 'Projetos', [
            'mode' => 'projects',
            'page' => $this->repository->paginateProjects([
                'q' => $request->query('q', ''), 'status' => $request->query('status', 'all'),
            ], $this->page($request->query('page', 1))),
        ]);
    }

    public function videos(Request $request): Response
    {
        return $this->render('admin.projects', 'Vídeos', [
            'mode' => 'videos',
            'page' => $this->repository->paginateVideos([
                'q' => $request->query('q', ''),
                'source' => $request->query('source', 'all'),
                'status' => $request->query('status', 'all'),
            ], $this->page($request->query('page', 1))),
        ]);
    }

    public function jobs(Request $request): Response
    {
        return $this->render('admin.jobs', 'Jobs', [
            'mode' => 'jobs',
            'page' => $this->repository->paginateJobs([
                'status' => $request->query('status', 'all'), 'type' => $request->query('type', ''),
            ], $this->page($request->query('page', 1))),
        ]);
    }

    public function errors(Request $request): Response
    {
        return $this->render('admin.jobs', 'Erros', [
            'mode' => 'errors',
            'page' => $this->repository->paginateJobs([
                'type' => $request->query('type', ''),
            ], $this->page($request->query('page', 1)), 25, true),
        ]);
    }

    public function credits(Request $request): Response
    {
        return $this->render('admin.users', 'Créditos', [
            'mode' => 'credits',
            'page' => $this->repository->paginateCredits(['q' => $request->query('q', '')], $this->page($request->query('page', 1))),
            'plans' => [],
        ]);
    }

    public function plans(Request $request): Response
    {
        return $this->render('admin.plans', 'Planos', ['plans' => $this->repository->plans()]);
    }

    public function createPlan(Request $request): Response
    {
        return $this->mutate('/admin/planos','Plano criado.',fn()=> $this->service->createPlan($this->actorId(),['slug'=>$request->input('slug',''),'name'=>$request->input('name',''),'price_cents'=>$this->nonNegativeInteger($request->input('price_cents')),'monthly_minutes'=>$this->nonNegativeInteger($request->input('monthly_minutes')),'credits'=>$this->nonNegativeInteger($request->input('credits')),'is_active'=>$request->input('is_active')==='1','features'=>['exports_hd'=>$request->input('exports_hd')==='1','priority_processing'=>$request->input('priority_processing')==='1','team_access'=>$request->input('team_access')==='1','limits'=>['max_upload_bytes'=>$this->positiveId($request->input('max_upload_bytes')),'storage_bytes'=>$this->positiveId($request->input('storage_bytes'))]] ]));
    }

    public function logs(Request $request): Response
    {
        return $this->render('admin.logs', 'Logs do sistema', [
            'page' => $this->logs->paginate([
                'level' => $request->query('level', 'all'), 'event' => $request->query('event', ''),
            ], $this->page($request->query('page', 1))),
        ]);
    }

    public function gemini(Request $request): Response
    {
        return $this->render('admin.gemini', 'Configuração Gemini', ['settings' => $this->gemini->publicState()]);
    }

    public function settings(Request $request): Response { return $this->render('admin.settings','Configurações gerais',['settings'=>$this->settings->branding()]); }
    public function saveSettings(Request $request): Response { return $this->mutate('/admin/configuracoes','Configurações atualizadas.',function()use($request):void{$old=$this->settings->branding();$logo=$this->brandAssets?->store('logo',is_array($_FILES['logo']??null)?$_FILES['logo']:null);$favicon=$this->brandAssets?->store('favicon',is_array($_FILES['favicon']??null)?$_FILES['favicon']:null);$this->settings->save(['name'=>$request->input('name',''),'description'=>$request->input('description',''),'logo_url'=>$logo??$old['logo_url'],'favicon_url'=>$favicon??$old['favicon_url']]);}); }
    public function promotions(Request $request): Response { return $this->render('admin.promotions','Promoções',['promotions'=>$this->promotionsList(),'plans'=>$this->repository->plans(true)]); }
    public function createPromotion(Request $request): Response { return $this->mutate('/admin/promocoes','Promoção criada.',fn()=> $this->promotions->create($this->promotionInput($request))); }
    /** @param array<string,string> $parameters */ public function updatePromotion(Request $request,array $parameters):Response{return $this->mutate('/admin/promocoes','Promoção atualizada.',fn()=> $this->promotions->update($this->positiveId($parameters['id']??null),$this->promotionInput($request)));}
    /** @param array<string,string> $parameters */ public function deletePromotion(Request $request,array $parameters): Response
    {
        return $this->mutate('/admin/promocoes','Promoção removida.',function() use($request,$parameters):void {
            if ($request->input('confirm')!=='EXCLUIR') throw new \InvalidArgumentException('Confirme a exclusão.');
            $this->promotions->delete($this->positiveId($parameters['id']??null));
        });
    }
    private function promotionsList(): array { return $this->promotions->all(); }
    private function promotionInput(Request $request):array{return ['title'=>$request->input('title',''),'body'=>$request->input('body',''),'cta_label'=>$request->input('cta_label',''),'cta_url'=>$request->input('cta_url',''),'image_url'=>$request->input('image_url',''),'delivery_kind'=>$request->input('delivery_kind','banner'),'placement'=>$request->input('placement',''),'audience'=>$request->input('audience',''),'plan_id'=>$request->input('plan_id'),'user_id'=>$request->input('user_id'),'starts_at'=>$request->input('starts_at'),'ends_at'=>$request->input('ends_at'),'is_active'=>$request->input('is_active')==='1'];}

    /** @param array<string,string> $parameters */
    public function changeUserStatus(Request $request, array $parameters): Response
    {
        return $this->mutate('/admin/usuarios', 'Status do usuário atualizado.', function () use ($request, $parameters): void {
            $this->service->changeUserStatus(
                $this->actorId(),
                $this->positiveId($parameters['id'] ?? null),
                trim((string) $request->input('status', '')),
                (string) $request->input('reason', '')
            );
        });
    }

    /** @param array<string,string> $parameters */
    public function assignPlan(Request $request, array $parameters): Response
    {
        return $this->mutate('/admin/usuarios', 'Plano do usuário atualizado.', function () use ($request, $parameters): void {
            $this->service->assignPlan(
                $this->actorId(),
                $this->positiveId($parameters['id'] ?? null),
                $this->positiveId($request->input('plan_id')),
                (string) $request->input('reason', '')
            );
        });
    }

    public function adjustCredits(Request $request): Response
    {
        return $this->mutate('/admin/creditos', 'Saldo de créditos atualizado.', function () use ($request): void {
            $this->service->adjustCredits(
                $this->actorId(),
                $this->positiveId($request->input('user_id')),
                $this->integer($request->input('amount')),
                (string) $request->input('reason', '')
            );
        });
    }

    /** @param array<string,string> $parameters */
    public function updatePlan(Request $request, array $parameters): Response
    {
        return $this->mutate('/admin/planos', 'Plano atualizado.', function () use ($request, $parameters): void {
            $this->service->updatePlan($this->actorId(), $this->positiveId($parameters['id'] ?? null), [
                'name' => trim((string) $request->input('name', '')),
                'price_cents' => $this->nonNegativeInteger($request->input('price_cents')),
                'monthly_minutes' => $this->nonNegativeInteger($request->input('monthly_minutes')),
                'credits' => $this->nonNegativeInteger($request->input('credits')),
                'is_active' => $request->input('is_active') === '1',
                'features' => [
                    'exports_hd' => $request->input('exports_hd') === '1',
                    'priority_processing' => $request->input('priority_processing') === '1',
                    'team_access' => $request->input('team_access') === '1',
                    'limits' => [
                        'max_upload_bytes' => $this->positiveId($request->input('max_upload_bytes')),
                        'storage_bytes' => $this->positiveId($request->input('storage_bytes')),
                    ],
                ],
            ]);
        });
    }

    public function saveGemini(Request $request): Response
    {
        try {
            $this->gemini->save($this->actorId(), [
                'model' => trim((string) $request->input('model', '')),
                'api_key' => (string) $request->input('api_key', ''),
                'clear_api_key' => $request->input('clear_api_key') === '1',
            ]);
            Session::flash('admin_flash', ['type' => 'success', 'message' => 'Configuração Gemini atualizada.']);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage() === 'Application encryption key is invalid.'
                ? 'Configure uma APP_ENCRYPTION_KEY válida antes de salvar a chave Gemini. Nenhum segredo foi salvo.'
                : 'Não foi possível concluir a alteração. Verifique os dados e tente novamente.';
            Session::flash('admin_flash', ['type' => 'error', 'message' => $message]);
        } catch (Throwable) {
            Session::flash('admin_flash', ['type' => 'error', 'message' => 'Não foi possível concluir a alteração. Verifique os dados e tente novamente.']);
        }

        return Response::redirect('/admin/configuracoes/gemini');
    }

    public function testGemini(Request $request): Response
    {
        $result = $this->gemini->testConnection($this->actorId(), $request->clientIp());
        Session::flash('admin_flash', ['type' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']]);

        return Response::redirect('/admin/configuracoes/gemini');
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, string $title, array $data = []): Response
    {
        $identity = $this->repository->findIdentity($this->actorId());
        if (!is_array($identity)) {
            return Response::redirect('/login');
        }

        return $this->view->render($view, $data + [
            'title' => $title,
            'admin' => $identity,
            'flash' => Session::pull('admin_flash'),
        ]);
    }

    private function mutate(string $redirect, string $success, callable $operation): Response
    {
        try {
            $operation();
            Session::flash('admin_flash', ['type' => 'success', 'message' => $success]);
        } catch (Throwable) {
            Session::flash('admin_flash', ['type' => 'error', 'message' => 'Não foi possível concluir a alteração. Verifique os dados e tente novamente.']);
        }

        return Response::redirect($redirect);
    }

    private function actorId(): int
    {
        $id = (int) Session::get('user_id', 0);
        if ($id <= 0) {
            throw new \DomainException('Administrator session is invalid.');
        }

        return $id;
    }

    private function page(mixed $value): int
    {
        try {
            return $this->positiveId($value);
        } catch (Throwable) {
            return 1;
        }
    }

    private function positiveId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Positive integer is required.');
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new \InvalidArgumentException('Positive integer is required.');
        }

        return (int) $value;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if ($value === 0 || $value === '0') {
            return 0;
        }

        return $this->positiveId($value);
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]{0,18})\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Integer is required.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new \InvalidArgumentException('Integer is required.');
        }

        return $integer;
    }
}
