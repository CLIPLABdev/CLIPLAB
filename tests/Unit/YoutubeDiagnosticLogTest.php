<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Repositories\SystemLogRepository;
final class YoutubeDiagnosticLogTest extends TestCase
{
    public function testStoresOnlyBoundedSafeDiagnosticFields():void
    {
        $pdo=new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE system_logs (level TEXT,event_code TEXT,public_message TEXT,context_json TEXT,actor_id INTEGER,target_type TEXT,target_id INTEGER)');
        $logs=new SystemLogRepository($pdo);
        self::assertTrue($logs->tryRecord('warning','youtube.import_diagnostic',['video_id'=>'O1FZD5Zove0','result_code'=>'youtube_bot_challenge','exit_code'=>1,'output_bytes'=>0,'limit_bytes'=>1048576,'stderr'=>'secret token','url'=>'https://private.example/?secret=key']));
        $json=$pdo->query('SELECT context_json FROM system_logs')->fetchColumn();
        self::assertSame(['video_id'=>'O1FZD5Zove0','result_code'=>'youtube_bot_challenge','exit_code'=>1,'output_bytes'=>0,'limit_bytes'=>1048576],json_decode($json,true));
        self::assertStringNotContainsString('secret',$json);
        $logs->record('warning','youtube.import_diagnostic',['video_id'=>'invalid secret','result_code'=>'arbitrary secret','exit_code'=>-5,'output_bytes'=>'secret']);
        self::assertSame([],json_decode($pdo->query('SELECT context_json FROM system_logs LIMIT 1 OFFSET 1')->fetchColumn(),true));
        $logs->record('warning','youtube.import_diagnostic',['result_code'=>'unsafe_source_url']);
        self::assertSame(['result_code'=>'unsafe_source_url'],json_decode($pdo->query('SELECT context_json FROM system_logs LIMIT 1 OFFSET 2')->fetchColumn(),true));
    }
}
