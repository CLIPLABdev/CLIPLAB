<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\AnalysisResponseValidator;
use App\Credits\CreditReservation;
use App\Queue\ClaimedJob;
use App\Queue\GenerateClipsHandler;
use App\Queue\ProcessingEffectGuard;
use PHPUnit\Framework\TestCase;

final class GenerateClipsHandlerTest extends TestCase
{
    public function testAutomaticExportFailurePreservesCompletedAnalysisClipsAndCharge(): void
    {
        $fixture=new GenerateClipsFixture();
        $handler=$fixture->handler(static function (): void {
            throw new \RuntimeException('automatic export unavailable');
        });

        $outcome=$handler->handle($fixture->job());

        self::assertSame('failed',$outcome->status());
        self::assertSame('processing_persistence_failed',$outcome->code());
        self::assertSame('completed',$fixture->state->analysisStatus);
        self::assertSame('consumed',$fixture->state->reservationStatus);
        self::assertSame(0,$fixture->state->refundCalls);
        self::assertSame(1,$fixture->state->materializeCalls);
        self::assertSame(['suggestions_ready'],$fixture->state->projectStates);
    }

    public function testAutomaticPreflightRunsAfterMaterializationOutsideEffectsAndPassesSnapshot():void
    {
        $fixture=new GenerateClipsFixture();
        $prepared=new \stdClass();
        $events=[];
        $handler=$fixture->handler(
            static function(int $analysis,int $project,int $owner,mixed $snapshot=null)use($fixture,$prepared,&$events):void {
                self::assertTrue($fixture->state->inEffect);
                self::assertSame($prepared,$snapshot);
                $events[]='schedule';
            },
            static function(int $analysis,int $project,int $owner)use($fixture,$prepared,&$events):object {
                self::assertSame([3,4,7],[$analysis,$project,$owner]);
                self::assertFalse($fixture->state->inEffect);
                self::assertSame('completed',$fixture->state->analysisStatus);
                self::assertSame('consumed',$fixture->state->reservationStatus);
                $events[]='preflight';
                return $prepared;
            }
        );
        self::assertSame('completed',$handler->handle($fixture->job())->status());
        self::assertSame(['preflight','schedule'],$events);
        self::assertSame(0,$fixture->state->refundCalls);
    }

    public function testPreflightFailureDefersWithoutSchedulingOrRefunding():void
    {
        $fixture=new GenerateClipsFixture();
        $calls=0;
        $handler=$fixture->handler(static function()use(&$calls):void { ++$calls; },
            static function():void { throw new \RuntimeException('private probe failure'); });
        $result=$handler->handle($fixture->job());
        self::assertSame('deferred',$result->status());
        self::assertSame(0,$calls);
        self::assertSame('completed',$fixture->state->analysisStatus);
        self::assertSame('consumed',$fixture->state->reservationStatus);
        self::assertSame(0,$fixture->state->refundCalls);
        self::assertSame(1,$fixture->state->materializeCalls);
    }

    public function testAutomaticExportDatabaseFailureDefersWithoutRefundingCompletedAnalysis(): void
    {
        $fixture=new GenerateClipsFixture();
        $handler=$fixture->handler(static function (): void {
            throw new \PDOException('automatic export database unavailable');
        });

        $outcome=$handler->handle($fixture->job());

        self::assertSame('deferred',$outcome->status());
        self::assertSame(15,$outcome->delaySeconds());
        self::assertSame('completed',$fixture->state->analysisStatus);
        self::assertSame('consumed',$fixture->state->reservationStatus);
        self::assertSame(0,$fixture->state->refundCalls);
        self::assertSame(1,$fixture->state->materializeCalls);
        self::assertSame(['suggestions_ready'],$fixture->state->projectStates);
    }
}

final class GenerateClipsFixture
{
    public GenerateClipsState $state;

    public function __construct()
    {
        $this->state=new GenerateClipsState();
    }

    public function handler(callable $automaticExports,?callable $preflight=null): GenerateClipsHandler
    {
        return new GenerateClipsHandler(
            new GenerateClipsAnalyses($this->state),
            new GenerateClipsClips($this->state),
            new GenerateClipsProjects($this->state),
            new GenerateClipsReservations($this->state),
            new GenerateClipsCredits($this->state),
            new AnalysisResponseValidator(),
            new GenerateClipsGuard($this->state),
            $automaticExports,
            $preflight
        );
    }

    public function job(): ClaimedJob
    {
        return new ClaimedJob(1,'media','generate_clips',4,['analysis_id'=>3,'reservation_id'=>9],'worker','lease',1,3);
    }
}

final class GenerateClipsState
{
    public string $analysisStatus='validating';
    public string $reservationStatus='reserved';
    public int $refundCalls=0;
    public int $materializeCalls=0;
    public array $projectStates=[];
    public bool $inEffect=false;

    public function reservation(): CreditReservation
    {
        return new CreditReservation(9,7,4,1,$this->reservationStatus);
    }

    public function analysis(): array
    {
        return [
            'id'=>3,
            'status'=>$this->analysisStatus,
            'duration_seconds'=>40,
            'prompt_version'=>'v1',
            'validated_response_json'=>'{"video_summary":"Resumo confiável","clips":[{"title":"Primeiro","start_time":0,"end_time":20,"duration":20,"score":90,"reason":"Motivo A","hook":"Gancho A","category":"insight"}]}',
        ];
    }
}

final class GenerateClipsAnalyses
{
    public function __construct(private GenerateClipsState $state) {}
    public function findForProject(int $analysisId,int $projectId): array { return $this->state->analysis(); }
    public function findLockedForProject(int $analysisId,int $projectId): array { return $this->state->analysis(); }
    public function markCompleted(int $analysisId): void { $this->state->analysisStatus='completed'; }
    public function markFailed(int $analysisId,string $code,string $message): void { $this->state->analysisStatus='failed'; }
}

final class GenerateClipsClips
{
    public function __construct(private GenerateClipsState $state) {}
    public function materialize(int $analysisId,int $projectId,object $result): void { ++$this->state->materializeCalls; }
}

final class GenerateClipsProjects
{
    public function __construct(private GenerateClipsState $state) {}
    public function ownerId(int $projectId): ?int { return $projectId===4 ? 7 : null; }
    public function advanceProcessingState(int $projectId,string $status,?string $code=null,?string $message=null): void { $this->state->projectStates[]=$status; }
    public function synchronizeRenderState(int $projectId): void {}
}

final class GenerateClipsReservations
{
    public function __construct(private GenerateClipsState $state) {}
    public function findById(int $reservationId): CreditReservation { return $this->state->reservation(); }
    public function findForAnalysis(int $reservationId,int $userId,int $projectId,int $units,string $promptVersion): CreditReservation { return $this->state->reservation(); }
}

final class GenerateClipsCredits
{
    public function __construct(private GenerateClipsState $state) {}
    public function costForDuration(int $durationSeconds): int { return 1; }
    public function consume(int $reservationId): CreditReservation { $this->state->reservationStatus='consumed'; return $this->state->reservation(); }
    public function refund(int $reservationId,string $reason): CreditReservation { ++$this->state->refundCalls; $this->state->reservationStatus='refunded'; return $this->state->reservation(); }
}

final class GenerateClipsGuard implements ProcessingEffectGuard
{
    public function __construct(private GenerateClipsState $state) {}
    public function apply(ClaimedJob $job,callable $effect): bool {
        $this->state->inEffect=true;
        try { $effect(); return true; } finally { $this->state->inEffect=false; }
    }
}
