<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Deployment\ProductionEnvironmentProbe;
use App\Deployment\ProductionReadiness;
use App\Gemini\GeminiHttpResponse;
use App\Gemini\GeminiTransport;
use App\Process\ProcessResult;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class DeploymentProductionProbeTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root=sys_get_temp_dir().'/readiness-fixture-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/private',0700,true);
        mkdir($this->root.'/public',0700,true);
        mkdir($this->root.'/database/migrations',0700,true);
        file_put_contents($this->root.'/database/migrations/001.sql','CREATE TABLE sample (id INT)');
    }
    protected function tearDown(): void
    {
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->root);
    }
    private function configuration(): array
    {
        return ['database'=>['dsn'=>'mysql:host=invalid.test;dbname=test','username'=>'fake','password'=>'PASSWORD_CANARY'],
            'media'=>['private_root'=>$this->root.'/private','ffmpeg_binary'=>'ffmpeg','ffprobe_binary'=>'ffprobe',
                'queue'=>['lease_seconds'=>400],'render_timeout_seconds'=>240,'download_timeout_seconds'=>120,'process_timeout_seconds'=>60],
            'gemini'=>['api_key'=>'FAKE_KEY_CANARY','model'=>'fake-model','http_timeout_seconds'=>180],
            'app_url'=>'https://example.test','encryption_key'=>''];
    }
    public function testUnavailableDatabaseAndMerelyConfiguredHttpsGeminiNeverPassOrLeakSecrets(): void
    {
        $probe=new ProductionEnvironmentProbe($this->root,$this->configuration(),
            static function (): PDO { throw new \RuntimeException('PASSWORD_CANARY'); },
            static fn (): ProcessResult => new ProcessResult(1,'','private/path CANARY'),null,
            static function (): array { throw new \LogicException('HTTP must require explicit verification'); });
        $caps=$probe->collect('all-in-one');
        self::assertFalse($caps['database']);
        self::assertFalse($caps['migrations']);
        self::assertFalse($caps['gemini']);
        self::assertFalse($caps['https']);
        self::assertTrue($caps['private_storage']);
        self::assertFalse((new ProductionReadiness())->evaluate('all-in-one',$caps)['ready']);
        self::assertStringNotContainsString('CANARY',json_encode($caps));
        self::assertSame([],glob($this->root.'/private/*'));
    }
    public function testWebQueriesActualSchemaAndNeverStartsMediaOrGemini(): void
    {
        $pdo=new ReadinessFakePdo();
        $probe=new ProductionEnvironmentProbe($this->root,$this->configuration(),static fn (): PDO => $pdo,
            static function (): void { throw new \LogicException('Web cannot start FFmpeg'); },null,
            static fn (): array => ['ready'=>true]);
        $caps=$probe->collect('web',true,false);
        self::assertTrue($caps['database']);
        self::assertTrue($caps['migrations']);
        self::assertTrue($caps['queue']);
        self::assertTrue($caps['https']);
        self::assertFalse($caps['gemini']);
        self::assertTrue((new ProductionReadiness())->evaluate('web',$caps)['ready']);
        self::assertTrue($pdo->readEditorSchema);
        self::assertTrue($pdo->readQueueSchema);
    }
    public function testMissingMigrationOrBrokenSchemaBlocksDespiteWorkingMysql(): void
    {
        foreach (['ledger','schema','engine'] as $failure) {
            $pdo=new ReadinessFakePdo($failure);
            $probe=new ProductionEnvironmentProbe($this->root,$this->configuration(),static fn (): PDO => $pdo);
            $caps=$probe->collect('web');
            self::assertFalse($caps[$failure==='engine' ? 'database' : 'migrations']);
        }
    }
    public function testPublicStorageAndInsufficientCaptionLeaseAreRejected(): void
    {
        $configuration=$this->configuration();
        $configuration['media']['private_root']=$this->root.'/public';
        $configuration['media']['queue']['lease_seconds']=200;
        $probe=new ProductionEnvironmentProbe($this->root,$configuration,static fn (): PDO => new ReadinessFakePdo());
        $caps=$probe->collect('worker');
        self::assertFalse($caps['private_storage']);
        self::assertFalse($caps['worker_budget']);
        self::assertSame([],glob($this->root.'/public/*'));
    }
    public function testSyntheticGeminiVerificationRequiresStoppedCandidateAndNeverExposesResponse(): void
    {
        foreach ([true,false] as $valid) {
            $transport=new class($valid) implements GeminiTransport {
                public function __construct(private bool $valid) {}
                public function request(string $method,string $url,array $headers,?string $body,int $timeoutSeconds,int $responseLimitBytes): GeminiHttpResponse {
                    TestCase::assertSame('POST',$method);
                    TestCase::assertSame('https://generativelanguage.googleapis.com/v1beta/models/fake-model:generateContent',$url);
                    TestCase::assertLessThanOrEqual(30,$timeoutSeconds);
                    TestCase::assertLessThanOrEqual(65536,$responseLimitBytes);
                    TestCase::assertStringNotContainsString('fileData',(string)$body);
                    $payload=json_decode((string)$body,true,32,JSON_THROW_ON_ERROR);
                    TestCase::assertGreaterThanOrEqual(256,$payload['generationConfig']['maxOutputTokens']);
                    TestCase::assertLessThanOrEqual(1024,$payload['generationConfig']['maxOutputTokens']);
                    $candidate=['finishReason'=>$this->valid ? 'STOP' : 'MAX_TOKENS',
                        'content'=>['parts'=>[['text'=>'PROVIDER_RESPONSE_CANARY']]]];
                    return new GeminiHttpResponse(200,[],json_encode(['candidates'=>[$candidate]]));
                }
                public function upload(string $url,array $headers,string $absolutePath,int $sizeBytes,int $timeoutSeconds,int $responseLimitBytes): GeminiHttpResponse { throw new \LogicException('No upload in readiness'); }
            };
            $probe=new ProductionEnvironmentProbe($this->root,$this->configuration(),static fn (): PDO => new ReadinessFakePdo(),
                static fn (): ProcessResult => new ProcessResult(1,'',''),$transport);
            $caps=$probe->collect('worker',false,true);
            self::assertSame($valid,$caps['gemini']);
            self::assertStringNotContainsString('CANARY',json_encode($caps));
        }
    }

    public function testSuccessfulExecutableWithoutReadableMediaCannotPassCapabilities(): void
    {
        $probe=new ProductionEnvironmentProbe($this->root,$this->configuration(),static fn (): PDO => new ReadinessFakePdo(),
            static fn (): ProcessResult => new ProcessResult(0,'ffmpeg version present',''));
        $caps=$probe->collect('worker');
        self::assertFalse($caps['ffmpeg']);
        self::assertFalse($caps['ffprobe']);
        self::assertFalse($caps['captions']);
        self::assertSame([],glob($this->root.'/private/*'));
    }

    public function testRealSyntheticEncodingCaptionsProbeAndWavExtractionWithoutDatabaseOrNetwork(): void
    {
        $ffmpeg=getenv('TEST_FFMPEG_BIN');
        $ffprobe=getenv('TEST_FFPROBE_BIN');
        if (!is_string($ffmpeg) || !is_file($ffmpeg) || !is_string($ffprobe) || !is_file($ffprobe)) {
            self::markTestSkipped('Set TEST_FFMPEG_BIN and TEST_FFPROBE_BIN for the real media readiness probe.');
        }
        $configuration=$this->configuration();
        $configuration['media']['ffmpeg_binary']=$ffmpeg;
        $configuration['media']['ffprobe_binary']=$ffprobe;
        $probe=new ProductionEnvironmentProbe($this->root,$configuration,
            static function (): PDO { throw new \RuntimeException('Database deliberately disabled'); });
        $caps=$probe->collect('worker');
        self::assertTrue($caps['ffmpeg'],'Real H264/AAC encoding with ASS');
        self::assertTrue($caps['ffprobe'],'Real stream and duration verification');
        self::assertTrue($caps['captions'],'Visible ASS pixels and WAV PCM extraction');
        self::assertFalse($caps['database']);
        self::assertFalse($caps['gemini']);
        self::assertSame([],glob($this->root.'/private/*'));
    }
}

final class ReadinessFakePdo extends PDO
{
    public bool $readEditorSchema=false;
    public bool $readQueueSchema=false;
    public function __construct(private string $failure='') {}
    public function getAttribute(int $attribute): mixed { return $attribute===PDO::ATTR_DRIVER_NAME ? 'mysql' : null; }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query==='SELECT VERSION()') return new ReadinessFakeStatement([$this->failure==='engine' ? '5.7.44' : '8.0.36']);
        if ($query==='SELECT migration FROM migrations') return new ReadinessFakeStatement($this->failure==='ledger' ? [] : ['001.sql']);
        if (str_contains($query,'clip_editor_profiles')) {
            $this->readEditorSchema=true;
            if ($this->failure==='schema') throw new \RuntimeException('Missing column');
        }
        if (str_contains($query,'processing_jobs')) $this->readQueueSchema=true;
        if (str_contains($query,'gemini_settings') && !str_contains($query,'WHERE 1=0')) return new ReadinessFakeStatement([]);
        return new ReadinessFakeStatement([1]);
    }
}
final class ReadinessFakeStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function fetchColumn(int $column=0): mixed { return $this->rows[0] ?? false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->rows[0] ?? false; }
}
