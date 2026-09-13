<?php
declare(strict_types=1);
namespace Tests\Support;

use PDO;
use PDOStatement;
use App\Ai\ViralClipPrompt;
use App\Repositories\{ProjectRepository, ProjectSourceRepository, AiAnalysisRepository, CreditReservationRepository, CreditTransactionRepository};
use App\Services\{AiPipelineStarter, CreditReservationService, DatabaseJobDispatcher, PlanQuotaService, ProjectAnalysisResumeService};

/** Isolated SQLite schema; translates MySQL upsert/row-lock syntax for local tests. */
final class AnalysisResumeDatabase extends PDO
{
    public function __construct(string $dsn = 'sqlite::memory:', bool $seed = true)
    {
        parent::__construct($dsn, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('UTC_TIMESTAMP', static fn()=>gmdate('Y-m-d H:i:s'));
        $this->exec('PRAGMA busy_timeout = 4000');
        if (!$seed) return;
        $this->exec(<<<'SQL'
CREATE TABLE plans(id INTEGER PRIMARY KEY,slug TEXT,name TEXT,price_cents INTEGER,monthly_minutes INTEGER,credits INTEGER,features TEXT,is_active INTEGER);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,status TEXT,plan_id INTEGER,credits INTEGER);
CREATE TABLE projects(id INTEGER PRIMARY KEY,user_id INTEGER,status TEXT,progress INTEGER,error_code TEXT,error_message TEXT,processed_duration_seconds INTEGER DEFAULT 0,usage_recorded_at TEXT,updated_at TEXT);
CREATE TABLE project_sources(id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,storage_disk TEXT,object_key TEXT,mime_type TEXT,sha256 TEXT,size_bytes INTEGER,duration_seconds INTEGER);
CREATE TABLE credit_transactions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,amount INTEGER,balance_after INTEGER,reference_type TEXT,reference_id INTEGER,description TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE credit_reservations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,project_id INTEGER,operation TEXT,units INTEGER,status TEXT,idempotency_key TEXT,credit_transaction_id INTEGER,UNIQUE(user_id,idempotency_key));
CREATE TABLE ai_analyses(id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,prompt_version TEXT,model TEXT,status TEXT,gemini_file_name TEXT,gemini_file_uri TEXT,gemini_file_mime TEXT,gemini_file_state TEXT,video_summary TEXT,validated_response_json TEXT,validation_attempts INTEGER DEFAULT 0,error_code TEXT,error_message TEXT,UNIQUE(project_id,prompt_version));
CREATE TABLE processing_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,queue_name TEXT,type TEXT,project_id INTEGER,payload_json TEXT,idempotency_key TEXT,max_attempts INTEGER,available_at TEXT,UNIQUE(queue_name,idempotency_key));
CREATE TABLE clips(id INTEGER PRIMARY KEY,project_id INTEGER,ai_analysis_id INTEGER);
ALTER TABLE credit_reservations ADD COLUMN refund_transaction_id INTEGER;
ALTER TABLE credit_reservations ADD COLUMN refunded_at TEXT;
ALTER TABLE credit_reservations ADD COLUMN consumed_at TEXT;
ALTER TABLE processing_jobs ADD COLUMN status TEXT DEFAULT 'queued';
ALTER TABLE processing_jobs ADD COLUMN attempts INTEGER DEFAULT 0;
ALTER TABLE processing_jobs ADD COLUMN worker_id TEXT;
ALTER TABLE processing_jobs ADD COLUMN lease_token_hash TEXT;
ALTER TABLE processing_jobs ADD COLUMN leased_until TEXT;
ALTER TABLE processing_jobs ADD COLUMN last_error_code TEXT;
ALTER TABLE processing_jobs ADD COLUMN last_error_message TEXT;
ALTER TABLE processing_jobs ADD COLUMN started_at TEXT;
ALTER TABLE processing_jobs ADD COLUMN finished_at TEXT;
ALTER TABLE processing_jobs ADD COLUMN progress INTEGER DEFAULT 0;
ALTER TABLE ai_analyses ADD COLUMN completed_at TEXT;
INSERT INTO plans VALUES(1,'test','Test',0,1000,0,'{}',1);
INSERT INTO users VALUES(7,'Owner','owner@example.test','active',1,66),(8,'Other','other@example.test','active',1,66);
INSERT INTO projects(id,user_id,status,progress,error_code,error_message) VALUES(11,7,'awaiting_credits',75,'insufficient_credits','Insuficiente');
SQL);
        $this->prepare("INSERT INTO project_sources VALUES(21,11,'ready','local','imports/11/video.mp4','video/mp4',?,1000,121)")->execute([str_repeat('a',64)]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('FOR UPDATE','',$query);
        return parent::prepare(str_replace('ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', 'ON CONFLICT(project_id,prompt_version) DO UPDATE SET id = id', $query), $options);
    }

    public function service(string $model = 'test-model'): ProjectAnalysisResumeService
    {
        return new ProjectAnalysisResumeService($this, fn(?string $existingModel)=>new AiPipelineStarter(
            $this,new ProjectRepository($this),new ProjectSourceRepository($this),new AiAnalysisRepository($this),
            new CreditReservationService($this,new CreditReservationRepository($this),new CreditTransactionRepository($this),1),
            new DatabaseJobDispatcher($this),ViralClipPrompt::VERSION,$existingModel ?? $model,new PlanQuotaService($this)
        ), new \App\Services\AiAnalysisRecoveryService($this));
    }

    public function failedAnalysis(string $code = 'ai_unavailable'): int
    {
        $this->service()->resume(11,7);
        $credits = new CreditReservationService($this,new CreditReservationRepository($this),new CreditTransactionRepository($this),1);
        $credits->refund(1,$code);
        $this->prepare("UPDATE projects SET status='failed',progress=100,error_code=?,error_message='Falha temporária'")->execute([$code]);
        $this->prepare("UPDATE ai_analyses SET status='failed',error_code=?,error_message='Falha temporária',gemini_file_name='files/old',gemini_file_uri='https://generativelanguage.googleapis.com/v1beta/files/old',gemini_file_mime='video/mp4',gemini_file_state='ACTIVE'")->execute([$code]);
        $this->prepare("UPDATE processing_jobs SET status='failed',attempts=3,last_error_code=?,last_error_message='Falha temporária',finished_at=CURRENT_TIMESTAMP")->execute([$code]);
        return (int)$this->query('SELECT refund_transaction_id FROM credit_reservations')->fetchColumn();
    }
}
