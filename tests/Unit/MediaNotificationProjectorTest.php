<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Communications\{MediaNotificationProjector,CommunicationEmitterService,CommunicationEventCatalog};
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class MediaNotificationProjectorTest extends TestCase
{
    private function fixture():array {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE plans(id INTEGER PRIMARY KEY,name TEXT)');$pdo->exec("INSERT INTO plans VALUES(1,'Criador')");
        $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,status TEXT,plan_id INTEGER)');$pdo->exec("INSERT INTO users VALUES(1,'Ana','ana@example.test','active',1)");
        $pdo->exec('CREATE TABLE projects(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,status TEXT,error_code TEXT)');
        $pdo->exec('CREATE TABLE ai_analyses(id INTEGER PRIMARY KEY,project_id INTEGER)');
        $pdo->exec('CREATE TABLE clips(id INTEGER PRIMARY KEY,project_id INTEGER,ai_analysis_id INTEGER,status TEXT,render_revision INTEGER,render_error_code TEXT)');
        $pdo->exec('CREATE TABLE processing_jobs(id INTEGER PRIMARY KEY,project_id INTEGER,queue_name TEXT,type TEXT,status TEXT,attempts INTEGER,last_error_code TEXT)');
        $pdo->exec('CREATE TABLE communication_media_checkpoints(source TEXT PRIMARY KEY,high_water_id INTEGER,cursor_id INTEGER,initialized_at TEXT,last_run_at TEXT,last_error_code TEXT)');
        $pdo->exec('CREATE TABLE communication_media_observations(source TEXT,entity_id INTEGER,last_state TEXT,last_revision TEXT,observed_at TEXT,PRIMARY KEY(source,entity_id))');
        $pdo->exec('CREATE TABLE communication_media_receipts(dedupe_key TEXT PRIMARY KEY,source TEXT,entity_id INTEGER,event TEXT,disposition TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE communication_preferences(user_id INTEGER,category TEXT,email_enabled INTEGER,in_app_enabled INTEGER,PRIMARY KEY(user_id,category))');
        $pdo->exec('CREATE TABLE communication_email_outbox(id INTEGER PRIMARY KEY,user_id INTEGER,recipient TEXT,event TEXT,category TEXT,payload_ciphertext TEXT,dedupe_key TEXT UNIQUE,status TEXT,available_at TEXT)');
        $pdo->exec('CREATE TABLE communication_notifications(id INTEGER PRIMARY KEY,user_id INTEGER,event TEXT,category TEXT,title TEXT,body TEXT,dedupe_key TEXT UNIQUE)');
        $emitter=new CommunicationEmitterService($pdo,new SecretCipher(base64_encode(str_repeat('k',32))),new CommunicationEventCatalog());
        return [$pdo,new MediaNotificationProjector($pdo,$emitter)];
    }
    public function testInitializationAndHistoricalTerminalBaselineNeverEnqueue():void {
        [$pdo,$s]=$this->fixture();$pdo->exec("INSERT INTO projects VALUES(1,1,'Antigo','completed',NULL)");
        $s->initialize();self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn());
        $result=$s->runBatch('projects',10);self::assertSame(1,$result['scanned']);self::assertSame(0,$result['emitted']);
        $pdo->exec("UPDATE projects SET status='rendering'");$s->runBatch('projects',10);$pdo->exec("UPDATE projects SET status='completed'");$s->runBatch('projects',10);
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn());
    }
    public function testNewTerminalsAndObservedActiveTransitionsEmitOnce():void {
        [$pdo,$s]=$this->fixture();$pdo->exec("INSERT INTO projects VALUES(1,1,'Em andamento','analyzing',NULL)");$s->initialize();$s->runBatch('projects',10);
        $pdo->exec("UPDATE projects SET status='suggestions_ready' WHERE id=1");$pdo->exec("INSERT INTO projects VALUES(2,1,'Novo','failed','worker_error')");
        self::assertSame(2,$s->runBatch('projects',10)['emitted']);self::assertSame(0,$s->runBatch('projects',10)['emitted']);
        self::assertSame(['media.processing_completed','media.processing_failed'],$pdo->query('SELECT event FROM communication_email_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM communication_notifications')->fetchColumn());
    }
    public function testClipRevisionDedupeAndStaleAnalysisSuppression():void {
        [$pdo,$s]=$this->fixture();$s->initialize();$pdo->exec("INSERT INTO projects VALUES(1,1,'Projeto','rendering',NULL)");$pdo->exec('INSERT INTO ai_analyses VALUES(1,1)');
        $pdo->exec("INSERT INTO clips VALUES(1,1,1,'completed',1,NULL)");self::assertSame(1,$s->runBatch('clips',10)['emitted']);self::assertSame(0,$s->runBatch('clips',10)['emitted']);
        $pdo->exec('UPDATE clips SET render_revision=2');self::assertSame(1,$s->runBatch('clips',10)['emitted']);
        $pdo->exec('INSERT INTO ai_analyses VALUES(2,1)');$pdo->exec('UPDATE clips SET render_revision=3');self::assertSame(0,$s->runBatch('clips',10)['emitted']);
    }
    public function testQuotaSignalsRespectBothChannelPreferencesAndInactiveUsers():void {
        [$pdo,$s]=$this->fixture();$s->initialize();$pdo->exec("INSERT INTO communication_preferences VALUES(1,'usage',0,1)");$pdo->exec("INSERT INTO projects VALUES(1,1,'Sem saldo','awaiting_credits','insufficient_credits')");
        self::assertSame(1,$s->runBatch('projects',10)['emitted']);self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn());self::assertSame('media.usage_limit_reached',$pdo->query('SELECT event FROM communication_notifications')->fetchColumn());
        $pdo->exec("UPDATE users SET status='suspended'");$pdo->exec("INSERT INTO projects VALUES(2,1,'Suspenso','completed',NULL)");self::assertSame(0,$s->runBatch('projects',10)['emitted']);
    }
    public function testBatchCheckpointAndOutboxRollbackTogetherOnPersistenceFailure():void {
        [$pdo,$s]=$this->fixture();$s->initialize();$pdo->exec("INSERT INTO projects VALUES(1,1,'Novo','completed',NULL),(2,1,'Outro','completed',NULL)");
        self::assertSame(1,$s->runBatch('projects',1)['scanned']);self::assertSame(1,(int)$pdo->query("SELECT cursor_id FROM communication_media_checkpoints WHERE source='projects'")->fetchColumn());
        $pdo->exec('DROP TABLE communication_email_outbox');try{$s->runBatch('projects',1);self::fail('Failure swallowed');}catch(\RuntimeException){}
        self::assertSame(1,(int)$pdo->query("SELECT cursor_id FROM communication_media_checkpoints WHERE source='projects'")->fetchColumn());self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM communication_notifications')->fetchColumn());self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM communication_media_receipts')->fetchColumn());self::assertSame('projection_failed',$pdo->query("SELECT last_error_code FROM communication_media_checkpoints WHERE source='projects'")->fetchColumn());
    }
    public function testJobTerminalFailureIsProjectedButCompletionAndMirroredFailureAreNot():void {
        [$pdo,$s]=$this->fixture();$s->initialize();$pdo->exec("INSERT INTO projects VALUES(1,1,'Projeto','analyzing',NULL),(2,1,'Falhou','failed','worker_error')");
        $pdo->exec("INSERT INTO processing_jobs VALUES(1,1,'media','analyze_video','failed',3,'worker_error'),(2,1,'media','probe_video','completed',1,NULL),(3,2,'media','analyze_video','failed',3,'worker_error')");
        self::assertSame(1,$s->runBatch('jobs',10)['emitted']);self::assertSame(0,$s->runBatch('jobs',10)['emitted']);
    }
    public function testUninitializedProjectorFailsClosed():void {
        [$pdo,$s]=$this->fixture();$this->expectException(\LogicException::class);$s->runBatch('projects',10);
    }
}
