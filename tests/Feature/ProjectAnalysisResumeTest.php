<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Controllers\ProjectAnalysisResumeController;
use App\Controllers\ProjectSuggestionController;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class ProjectAnalysisResumeTest extends TestCase
{
    protected function setUp(): void { $_SESSION = ['user_id'=>7]; }
    protected function tearDown(): void { $_SESSION = []; }

    public function testWaitingProjectOffersCsrfProtectedResumeAndEscapesFeedback(): void
    {
        Session::flash('analysis_resume_feedback', '<script>failure</script>');
        $controller = new ProjectSuggestionController(new View(), static fn()=>[
            'id'=>11,'name'=>'Waiting','status'=>'awaiting_credits','progress'=>75,
            'analysis_status'=>'queued','updated_at'=>'2026-09-07',
        ], static fn()=>[]);
        $html = $controller->show(Request::fake('GET','/projetos/11'), ['id'=>'11'])->body();
        self::assertStringContainsString('action="/projetos/11/retomar-analise"', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('Retomar análise', $html);
        self::assertStringContainsString('&lt;script&gt;failure&lt;/script&gt;', $html);
    }

    public function testControllerRejectsOverflowMalformedAndAnonymousIdsBeforeScheduling(): void
    {
        $controller = new ProjectAnalysisResumeController(static function () { self::fail('Invalid request reached scheduler.'); });
        foreach (['0','01','-1','11x','9223372036854775808'] as $id) {
            self::assertSame(404, $controller->store(Request::fake('POST','/'), ['id'=>$id])->status());
        }
        $_SESSION = [];
        self::assertSame('/login', $controller->store(Request::fake('POST','/'), ['id'=>'11'])->header('Location'));
    }

    public function testLibraryLinksWaitingProjectAndOtherStatesDoNotOfferResume(): void
    {
        $html = (new View())->render('projects.index',[
            'title'=>'Projetos',
            'user'=>['id'=>7,'name'=>'Owner','email'=>'owner@example.test','credits'=>66,'plan_name'=>'Test','monthly_minutes'=>1000],
            'created'=>false,
            'projects'=>[['id'=>11,'name'=>'Waiting','status'=>'awaiting_credits','progress'=>75]],
        ])->body();
        self::assertStringContainsString('href="/projetos/11">Retomar análise</a>', $html);
        foreach (['ready','analyzing','failed','suggestions_ready','completed'] as $state) {
            $controller = new ProjectSuggestionController(new View(),static fn()=>[
                'id'=>11,'name'=>'Project','status'=>$state,'progress'=>75,'analysis_status'=>'queued','updated_at'=>'2026-09-07',
            ],static fn()=>[]);
            self::assertStringNotContainsString('retomar-analise',$controller->show(Request::fake('GET','/projetos/11'),['id'=>'11'])->body());
        }
    }

    public function testControllerRedirectsWithVisibleOutcomeAndHidesUnknownErrors(): void
    {
        $controller = new ProjectAnalysisResumeController(static fn(int $projectId, int $userId)=>$projectId===11 && $userId===7 ? 'ai_queued' : null);
        self::assertSame(404,$controller->store(Request::fake('POST','/'), ['id'=>'12'])->status());
        self::assertSame('/projetos/11',$controller->store(Request::fake('POST','/'), ['id'=>'11'])->header('Location'));
        self::assertNotEmpty(Session::pull('analysis_resume_feedback'));
        $controller = new ProjectAnalysisResumeController(static function () { throw new \RuntimeException('secret-password'); });
        self::assertSame(302,$controller->store(Request::fake('POST','/'), ['id'=>'11'])->status());
        self::assertStringNotContainsString('secret-password',Session::pull('analysis_resume_feedback'));
    }

    public function testRealRouteIsPostOnlyCsrfProtectedAndLazyForGuests(): void
    {
        $_SESSION = [];
        $router = require dirname(__DIR__,2).'/routes/web.php';
        self::assertSame(405,$router->dispatch(Request::fake('GET','/projetos/11/retomar-analise'))->status());
        self::assertSame(419,$router->dispatch(Request::fake('POST','/projetos/11/retomar-analise'))->status());
        self::assertSame('/login',$router->dispatch(Request::fake('POST','/projetos/11/retomar-analise',['_token'=>Csrf::token()]))->header('Location'));
    }

    public function testFailedTransientProjectOffersBoundRecoveryPostAndRejectsMalformedToken(): void
    {
        $controller = new ProjectSuggestionController(new View(), static fn()=>[
            'id'=>11,'name'=>'Failed','status'=>'failed','progress'=>100,'analysis_status'=>'failed','error_code'=>'ai_unavailable','updated_at'=>'2026-09-07',
        ],static fn()=>[],null,null,null,[],static fn(int $project,int $user)=>$project===11 && $user===7 ? 22 : null);
        $html = $controller->show(Request::fake('GET','/projetos/11'),['id'=>'11'])->body();
        self::assertStringContainsString('name="expected_refund_id" value="22"',$html);
        self::assertStringContainsString('Tentar análise novamente',$html);
        $resume = new ProjectAnalysisResumeController(static fn(int $project,int $user,?int $token)=>$token===22 ? 'ai_queued' : null);
        self::assertSame(302,$resume->store(Request::fake('POST','/',['expected_refund_id'=>'22']),['id'=>'11'])->status());
        foreach (['0','01','-2','9223372036854775808',['22']] as $token) {
            $resume = new ProjectAnalysisResumeController(static function(){ self::fail('Malformed recovery token reached service.'); });
            self::assertSame(404,$resume->store(Request::fake('POST','/',['expected_refund_id'=>$token]),['id'=>'11'])->status());
        }
    }
}
