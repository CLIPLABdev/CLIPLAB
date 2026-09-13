<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Gemini\CurlGeminiTransport;
use App\Gemini\GeminiTransport;
use App\Media\Editor\EditorOptions;
use App\Media\PrivateMediaRoot;
use App\Media\Subtitles\AssDocumentBuilder;
use App\Media\Subtitles\SrtCodec;
use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use App\Queue\WorkerLeaseBudget;
use App\Services\AdminGeminiSettingsService;
use PDO;
use RuntimeException;

final class ProductionEnvironmentProbe
{
    private $connect;
    private $run;
    private $http;

    /** Adapters isolate external effects in tests. Default adapters perform real bounded probes. */
    public function __construct(private string $sourceRoot,private array $configuration,?callable $connect=null,
        ?callable $run=null,private ?GeminiTransport $transport=null,?callable $http=null)
    {
        $this->connect=$connect ?? function (): PDO {
            $database=(array)($this->configuration['database'] ?? []);
            return new PDO((string)($database['dsn'] ?? ''),$database['username'] ?? null,$database['password'] ?? null,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
        };
        $this->run=$run ?? static function (array $command,string $temporary): ProcessResult {
            return (new ProcessRunner([$command[0]],$temporary))->run($command,30,1048576);
        };
        $this->http=$http ?? static fn (string $url): array => (new HttpSmoke())->run($url);
    }

    /** @return array<string,bool> */
    public function collect(string $role,bool $verifyHttp=false,bool $verifyGemini=false): array
    {
        (new ProductionReadiness())->evaluate($role,[]);
        $caps=array_fill_keys(['php_runtime','https','database','migrations','private_storage','queue','gemini','proc_open','ffprobe','ffmpeg','captions','worker_budget'],false);
        $caps['php_runtime']=PHP_VERSION_ID>=80000 && PHP_SAPI==='cli';
        foreach (['pdo','pdo_mysql','mbstring','fileinfo','curl','openssl'] as $extension) $caps['php_runtime']=$caps['php_runtime'] && extension_loaded($extension);
        $pdo=null;
        try {
            $pdo=($this->connect)();
            $caps['database']=$this->databaseSupported($pdo);
            if ($caps['database']) {
                $caps['migrations']=$this->attempt(fn (): bool => $this->migrationsReady($pdo));
                $caps['queue']=$this->attempt(static fn (): bool => $pdo->query('SELECT id, queue_name, type, payload_json, status, lease_token_hash, leased_until, available_at FROM processing_jobs WHERE 1=0')!==false);
            }
        } catch (\Throwable) {}
        $private=null;
        try {
            $media=(array)($this->configuration['media'] ?? []);
            $private=PrivateMediaRoot::resolve((string)($media['private_root'] ?? ''),$this->sourceRoot,$this->sourceRoot.'/public');
            $caps['private_storage']=$this->storageReady($private);
        } catch (\Throwable) {}
        if ($role!=='worker' && $verifyHttp) $caps['https']=$this->attempt(fn (): bool => (($this->http)((string)($this->configuration['app_url'] ?? '')))['ready']===true);
        if ($role!=='web') {
            $caps['proc_open']=function_exists('proc_open');
            $caps['worker_budget']=$this->attempt(fn (): bool => $this->workerBudget());
            if ($caps['proc_open'] && $caps['private_storage'] && is_string($private)) {
                $caps=array_replace($caps,$this->mediaCapabilities($private));
            }
            if ($verifyGemini && $pdo instanceof PDO && $caps['database'] && $caps['migrations']) {
                $caps['gemini']=$this->attempt(fn (): bool => $this->geminiConnected($pdo));
            }
        }
        return $caps;
    }

    private function databaseSupported(PDO $pdo): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return false;
        $version=$pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($version) || !preg_match('/([0-9]+\.[0-9]+\.[0-9]+)/',$version,$match)) return false;
        if (stripos($version,'mariadb')!==false) {
            return version_compare($match[1],'10.4.0','>=') && (string)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn()==='1';
        }
        return version_compare($match[1],'8.0.16','>=');
    }

    private function migrationsReady(PDO $pdo): bool
    {
        $files=glob($this->sourceRoot.'/database/migrations/*.sql') ?: [];
        if ($files===[]) return false;
        $expected=array_map('basename',$files);
        $applied=$pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($expected,$applied)!==[]) return false;
        // Ledger alone can survive an incomplete restore. Verify columns consumed by each module.
        $schemas=[
            'users'=>'id, plan_id, credits, role, status',
            'plans'=>'id, slug, name, price_cents, monthly_minutes, credits, features, is_active',
            'projects'=>'id, user_id, storage_bytes, processed_duration_seconds, usage_recorded_at, auto_render_requested',
            'project_sources'=>'id, project_id, object_key, duration_seconds, width, height, has_audio',
            'clips'=>'id, ai_analysis_id, render_revision, output_file, render_start_time, render_end_time',
            'credit_transactions'=>'id, user_id, amount, balance_after',
            'credit_reservations'=>'id, status',
            'clip_render_profiles'=>'id, clip_id, render_revision, reframe_mode',
            'clip_reframe_keyframes'=>'id, render_profile_id, at_ms, center_x, center_y',
            'clip_editor_profiles'=>'id, clip_id, render_revision, options_json, transcript_mode, request_key',
            'clip_subtitle_tracks'=>'id, editor_profile_id, status, language',
            'clip_subtitle_cues'=>'id, track_id, start_ms, end_ms, text, words_json',
            'user_consents'=>'id, user_id, purpose, policy_version, revoked_at',
            'system_logs'=>'id, event_code, public_message, context_json',
            'gemini_settings'=>'id, api_key_ciphertext, model, last_test_status',
        ];
        foreach ($schemas as $table=>$columns) if ($pdo->query('SELECT '.$columns.' FROM '.$table.' WHERE 1=0')===false) return false;
        return $pdo->query("SELECT id FROM plans WHERE slug='free' AND is_active=1 LIMIT 1")->fetchColumn()!==false;
    }

    private function storageReady(string $directory): bool
    {
        if (!is_dir($directory) || !is_readable($directory) || !is_writable($directory)) return false;
        $first=$directory.DIRECTORY_SEPARATOR.'readiness-'.bin2hex(random_bytes(12));
        $second=$first.'.verified';
        $handle=null;
        try {
            $handle=@fopen($first,'x+b');
            if ($handle===false) return false;
            @chmod($first,0600);
            $bytes=random_bytes(32);
            if (fwrite($handle,$bytes)!==32 || !fflush($handle)) return false;
            fclose($handle); $handle=null;
            if (!rename($first,$second)) return false;
            return file_get_contents($second)===$bytes;
        } finally {
            if (is_resource($handle)) fclose($handle);
            foreach ([$first,$second] as $path) if (is_file($path)) @unlink($path);
        }
    }

    private function workerBudget(): bool
    {
        $media=(array)($this->configuration['media'] ?? []);
        $gemini=(array)($this->configuration['gemini'] ?? []);
        $needed=WorkerLeaseBudget::requiredSeconds((int)($gemini['http_timeout_seconds'] ?? 180),
            (int)($media['render_timeout_seconds'] ?? 240),(int)($media['download_timeout_seconds'] ?? 120),
            (int)($media['process_timeout_seconds'] ?? 60),30,true,
            ($media['youtube_import_enabled'] ?? false) ? (int)($media['yt_dlp_timeout_seconds'] ?? 60) : 0,
            ($media['youtube_import_enabled'] ?? false) ? (int)($media['process_timeout_seconds'] ?? 60) : 0);
        return (int)($media['queue']['lease_seconds'] ?? 0)>=$needed;
    }

    /** Actually encode H264/AAC with libass, inspect streams, then decode to verify visible caption pixels. */
    private function mediaCapabilities(string $private): array
    {
        $caps=['ffmpeg'=>false,'ffprobe'=>false,'captions'=>false];
        $directory=$private.DIRECTORY_SEPARATOR.'readiness-media-'.bin2hex(random_bytes(12));
        if (!@mkdir($directory,0700)) return $caps;
        $ass=$directory.DIRECTORY_SEPARATOR.'captions.ass';
        $video=$directory.DIRECTORY_SEPARATOR.'sample.mp4';
        $wav=$directory.DIRECTORY_SEPARATOR.'sample.wav';
        try {
            $transcript=SrtCodec::parse("1\n00:00:00,000 --> 00:00:01,000\nClipForge\n",1000);
            $document=AssDocumentBuilder::build($transcript,EditorOptions::fromArray(['style'=>'minimal']),160,90,1000);
            if (file_put_contents($ass,$document)!==strlen($document)) return $caps;
            @chmod($ass,0600);
            $media=(array)($this->configuration['media'] ?? []);
            $ffmpeg=(string)($media['ffmpeg_binary'] ?? 'ffmpeg');
            $ffprobe=(string)($media['ffprobe_binary'] ?? 'ffprobe');
            $filterPath=str_replace(['\\',':',"'",',','[',']'],['/','\\:',"\\'",'\\,','\\[','\\]'],$ass);
            $result=($this->run)([$ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-f','lavfi','-i','color=c=black:s=160x90:r=10:d=1',
                '-f','lavfi','-i','anullsrc=r=16000:cl=mono','-t','1','-filter_threads','1','-vf',"subtitles=filename='".$filterPath."'",
                '-c:v','libx264','-threads','1','-pix_fmt','yuv420p','-c:a','aac','-movflags','+faststart','-y',$video],$directory);
            if ($result->exitCode!==0 || !is_file($video) || filesize($video)<1 || filesize($video)>2097152) return $caps;
            $caps['ffmpeg']=true;
            $result=($this->run)([$ffprobe,'-v','error','-show_entries','stream=codec_type,codec_name,width,height:format=duration','-of','json',$video],$directory);
            if ($result->exitCode!==0) return $caps;
            $data=json_decode($result->stdout,true,16,JSON_THROW_ON_ERROR);
            $hasVideo=false; $hasAudio=false;
            foreach ($data['streams'] ?? [] as $stream) {
                $hasVideo=$hasVideo || (($stream['codec_type'] ?? '')==='video' && ($stream['codec_name'] ?? '')==='h264' && ($stream['width'] ?? 0)===160 && ($stream['height'] ?? 0)===90);
                $hasAudio=$hasAudio || (($stream['codec_type'] ?? '')==='audio' && ($stream['codec_name'] ?? '')==='aac');
            }
            $duration=(float)($data['format']['duration'] ?? 0);
            if (!$hasVideo || !$hasAudio || $duration<0.9 || $duration>1.5) return $caps;
            $caps['ffprobe']=true;
            $decoded=($this->run)([$ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-i',$video,'-frames:v','1','-vf','format=gray','-f','rawvideo','pipe:1'],$directory);
            if ($decoded->exitCode!==0 || strlen($decoded->stdout)!==14400) return $caps;
            $pixels=unpack('C*',$decoded->stdout);
            if (max($pixels)-min($pixels)<32) return $caps;
            $audio=($this->run)([$ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-i',$video,'-t','1','-vn','-ac','1','-ar','16000','-acodec','pcm_s16le','-y',$wav],$directory);
            $wavBytes=is_file($wav) ? file_get_contents($wav) : false;
            $caps['captions']=$audio->exitCode===0 && is_string($wavBytes) && strlen($wavBytes)>32000 && strlen($wavBytes)<65536
                && substr($wavBytes,0,4)==='RIFF' && substr($wavBytes,8,4)==='WAVE';
        } catch (\Throwable) {
            // Only capability booleans leave this boundary; no paths, commands or stderr.
        } finally {
            foreach ([$ass,$video,$wav] as $path) if (is_file($path) || is_link($path)) @unlink($path);
            @rmdir($directory);
        }
        return $caps;
    }

    private function geminiConnected(PDO $pdo): bool
    {
        $baseline=(array)($this->configuration['gemini'] ?? []);
        $settings=new AdminGeminiSettingsService($pdo,$baseline,(string)($this->configuration['encryption_key'] ?? ''));
        $effective=$settings->effective();
        $key=(string)($effective['api_key'] ?? '');
        $model=(string)($effective['model'] ?? '');
        if ($key==='' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',$model)!==1) return false;
        $payload=['contents'=>[['role'=>'user','parts'=>[['text'=>'Responda apenas OK. Teste sintético de configuração.']]]],
            'generationConfig'=>['maxOutputTokens'=>512,'temperature'=>0]];
        $response=($this->transport ??= new CurlGeminiTransport())->request('POST',
            'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent',
            ['content-type'=>'application/json','x-goog-api-key'=>$key],json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),30,65536);
        if ($response->status()<200 || $response->status()>=300) return false;
        $decoded=json_decode($response->body(),true,32,JSON_THROW_ON_ERROR);
        foreach ($decoded['candidates'] ?? [] as $candidate) {
            if (($candidate['finishReason'] ?? null)!=='STOP') continue;
            foreach ($candidate['content']['parts'] ?? [] as $part) if (is_string($part['text'] ?? null) && trim($part['text'])!=='') return true;
        }
        return false;
    }

    private function attempt(callable $probe): bool { try { return $probe()===true; } catch (\Throwable) { return false; } }
}
