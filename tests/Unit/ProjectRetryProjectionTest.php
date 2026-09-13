<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Core\View;
use App\Repositories\ProjectRepository;
use App\Services\ProjectStatusService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectRetryProjectionTest extends TestCase
{
    public function testTransientRetryShowsWaitingWithoutChangingPersistedStatusOrLeakingDiagnostics(): void
    {
        $row = $this->retryRow();
        $status = (new ProjectStatusService(static fn()=>$row))->forOwnedProject(11,7);
        self::assertSame('analyzing',$status['status']);
        self::assertSame('Aguardando nova tentativa',$status['stage']);
        self::assertStringContainsString('automaticamente',$status['message']);
        self::assertStringContainsString('2 de 6',$status['message']);
        self::assertStringContainsString('07/09/2026 18:43:00 UTC',$status['message']);
        self::assertStringNotContainsString('secret',json_encode($status));
    }

    public function testRunningDeferredWithoutErrorAndFailedRemainAccurate(): void
    {
        foreach ([['running','ai_unavailable','analyzing','Analisando com IA'],['retry',null,'analyzing','Analisando com IA'],
            ['retry','ai_provider_rejected','analyzing','Analisando com IA'],['retry','ai_unavailable','failed','Falhou']] as [$job,$error,$project,$stage]) {
            $row = array_replace($this->retryRow(),['analysis_job_status'=>$job,'analysis_job_error_code'=>$error,'status'=>$project,'error_code'=>'ai_timeout']);
            $status = (new ProjectStatusService(static fn()=>$row))->forOwnedProject(11,7);
            self::assertSame($stage,$status['stage']);
            self::assertSame($project,$status['status']);
        }
    }

    public function testMalformedRetryDetailsNeverLeakOrInventAttemptCounts(): void
    {
        $row = array_replace($this->retryRow(),['analysis_job_attempts'=>'<secret>','analysis_job_max_attempts'=>999,'analysis_job_available_at'=>'secret-url']);
        $status = (new ProjectStatusService(static fn()=>$row))->forOwnedProject(11,7);
        self::assertSame('Aguardando nova tentativa',$status['stage']);
        self::assertStringNotContainsString('secret',json_encode($status));
        self::assertStringNotContainsString('999',$status['message']);
    }

    public function testRepositoryProjectsCurrentAnalysisJobInListDetailAndEndpoint(): void
    {
        $pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(<<<'SQL'
CREATE TABLE projects(id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,status TEXT,progress INTEGER,error_code TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE project_sources(id INTEGER PRIMARY KEY,project_id INTEGER,source_type TEXT,duration_seconds INTEGER,size_bytes INTEGER,width INTEGER,height INTEGER,video_codec TEXT,audio_codec TEXT,has_audio INTEGER,original_name TEXT);
CREATE TABLE ai_analyses(id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,video_summary TEXT);
CREATE TABLE clips(id INTEGER PRIMARY KEY,ai_analysis_id INTEGER);
CREATE TABLE processing_jobs(id INTEGER PRIMARY KEY,project_id INTEGER,type TEXT,queue_name TEXT,payload_json TEXT,status TEXT,last_error_code TEXT,attempts INTEGER,max_attempts INTEGER,available_at TEXT);
INSERT INTO projects VALUES(11,7,'Video','analyzing',88,NULL,'2026-09-07','2026-09-07');
INSERT INTO ai_analyses VALUES(1,11,'generating',NULL);
INSERT INTO processing_jobs VALUES(1,11,'analyze_video','media','{"analysis_id":1}','retry','ai_unavailable',1,6,'2026-09-07 18:43:00');
INSERT INTO processing_jobs VALUES(2,11,'render_clip','media','{}','running',NULL,1,3,'2026-09-07');
SQL);
        $repository = new ProjectRepository($pdo);
        foreach ([$repository->listForUser(7)[0],$repository->detailForOwnedProject(11,7),$repository->statusForOwnedProject(11,7)] as $row) {
            self::assertSame('retry',$row['analysis_job_status'] ?? null);
            self::assertSame('Aguardando nova tentativa',(new ProjectStatusService(static fn()=>$row))->forOwnedProject(11,7)['stage']);
        }
        self::assertNull($repository->statusForOwnedProject(11,8));
        $pdo->exec("INSERT INTO ai_analyses VALUES(2,11,'generating',NULL)");
        self::assertNull($repository->statusForOwnedProject(11,7)['analysis_job_status']);
        self::assertSame('retry',$pdo->query('SELECT status FROM processing_jobs WHERE id=1')->fetchColumn());
    }

    public function testLibraryFirstRenderShowsRetryWithoutJavascript(): void
    {
        $_SESSION=[];
        $html = (new View())->render('projects.index',[
            'title'=>'Projetos','created'=>false,'user'=>['id'=>7,'name'=>'Owner','email'=>'test@example.test','credits'=>1,'plan_name'=>'Test','monthly_minutes'=>100],
            'projects'=>[$this->retryRow()+['name'=>'Video','created_at'=>'2026-09-07']],
        ])->body();
        self::assertStringContainsString('Aguardando nova tentativa',$html);
        self::assertStringContainsString('data-project-status-url="/api/projects/11/status"',$html);
        self::assertStringContainsString('status-analyzing',$html);
    }

    private function retryRow(): array
    {
        return ['id'=>11,'status'=>'analyzing','progress'=>88,'analysis_status'=>'generating',
            'analysis_job_status'=>'retry','analysis_job_error_code'=>'ai_unavailable','analysis_job_attempts'=>1,'analysis_job_max_attempts'=>6,
            'analysis_job_available_at'=>'2026-09-07 18:43:00','last_error_message'=>'secret','payload_json'=>'secret'];
    }
}
