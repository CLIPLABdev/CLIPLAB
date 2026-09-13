<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Gemini\GeminiException;
use App\Gemini\GeminiHttpResponse;
use App\Repositories\SystemLogRepository;
use PDO;
use PHPUnit\Framework\TestCase;
final class GeminiFailureDiagnosticsTest extends TestCase
{
    public function testHttpRetryHintsAreBoundedAndOnlyUsedForTransientErrors(): void
    {
        self::assertTrue(method_exists(GeminiException::class,'fromHttpResponse'), 'HTTP diagnostics factory is missing.');
        $now=1700000000;
        foreach ([
            [503,['Retry-After'=>'99999999999999999999999'],900],
            [429,['Retry-After'=>gmdate('D, d M Y H:i:s', $now+90).' GMT'],90],
            [503,['Retry-After'=>gmdate('D, d M Y H:i:s', $now-1).' GMT'],null],
            [503,['Retry-After'=>['30','90']],null],
            [503,['Retry-After'=>'1.5'],null],
            [503,['Retry-After'=>"secret-key"],null],
            [400,['Retry-After'=>'30'],null],
            [403,['Retry-After'=>'30'],null],
        ] as [$status,$headers,$expected]) {
            $exception=GeminiException::fromHttpResponse(new GeminiHttpResponse($status,$headers,'private body'),$now);
            self::assertSame($expected,$exception->retryAfterSeconds());
            self::assertSame($status,$exception->safeDiagnostic()['http_status']);
            self::assertStringNotContainsString('private body',json_encode($exception->safeDiagnostic()));
        }
    }

    public function testDiagnosticsDiscardUnknownFieldsAndUnsafeValuesBeforePersistence(): void
    {
        $pdo=new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE system_logs(id INTEGER PRIMARY KEY,level TEXT,event_code TEXT,public_message TEXT,context_json TEXT,actor_id INTEGER,target_type TEXT,target_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $repo=new SystemLogRepository($pdo);
        $repo->record('warning','ai.provider_failure',[
            'job_id'=>15,'project_id'=>20,'analysis_id'=>25,'attempt'=>2,'max_attempts'=>6,'phase'=>'generate',
            'result_code'=>'ai_unavailable','failure_kind'=>'http','http_status'=>503,'curl_errno'=>0,'retry_after_seconds'=>120,
            'api_key'=>'secret','body'=>'secret','url'=>'https://private/?secret','model'=>'secret','exception_class'=>'secret',
        ]);
        $row=$pdo->query('SELECT event_code,context_json FROM system_logs')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ai.provider_failure',$row['event_code']);
        $context=json_decode($row['context_json'],true);
        self::assertSame(503,$context['http_status']);self::assertSame(120,$context['retry_after_seconds']);self::assertSame('generate',$context['phase']);
        self::assertStringNotContainsString('secret',$row['context_json']);
        $repo->record('warning','ai.provider_failure',['http_status'=>'503secret','curl_errno'=>-1,'phase'=>'secret','result_code'=>'secret','failure_kind'=>'secret']);
        self::assertSame('[]',$pdo->query('SELECT context_json FROM system_logs ORDER BY id DESC LIMIT 1')->fetchColumn());
    }
}
