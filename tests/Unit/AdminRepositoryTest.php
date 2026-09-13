<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\AdminRepository;
use App\Repositories\SystemLogRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestDatabase;

final class AdminRepositoryTest extends TestCase
{
    public function testSuccessfulMediaMetricsExcludeDraftFailedAndProcessingRecords(): void
    {
        $pdo=AdminTestDatabase::create(); $ids=AdminTestDatabase::seed($pdo);
        $project=$pdo->prepare('INSERT INTO projects(user_id,name,status) VALUES(?,?,?)');
        foreach(['draft','processing','failed','suggestions_ready','completed'] as $status) $project->execute([$ids['user_id'],'Media',$status]);
        $clip=$pdo->prepare('INSERT INTO clips(project_id,status) VALUES(1,?)');
        foreach(['draft','rendering','failed','completed','completed'] as $status) $clip->execute([$status]);
        $metrics=(new AdminRepository($pdo))->dashboard();
        self::assertSame(2,$metrics['videos_processed']??null);
        self::assertSame(2,$metrics['clips_completed']??null);
        self::assertSame(5,$metrics['clips_total']);
    }

    public function testDashboardAndPaginatedUsersUseRealAggregatesAndAllowlistedFilters(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $pdo->prepare('INSERT INTO projects (user_id, name, status, progress, storage_bytes) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ids['user_id'], 'Projeto real', 'processing', 42, 2048]);
        $projectId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO processing_jobs (type, project_id, payload_json, idempotency_key, status, progress) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['analyze', $projectId, '{"private":"never"}', str_repeat('a', 64), 'queued', 0]);

        $repository = new AdminRepository($pdo);
        $dashboard = $repository->dashboard();

        self::assertSame(3, $dashboard['users_total']);
        self::assertSame(1, $dashboard['projects_total']);
        self::assertSame(1, $dashboard['jobs_queued']);
        self::assertSame(2048, $dashboard['storage_bytes']);
        self::assertSame(0, $dashboard['users_suspended']);
        self::assertSame(0, $dashboard['clips_total']);
        self::assertSame([], $dashboard['recent_activity']);

        $invalid = $repository->paginateUsers(['status' => "active' OR 1=1 --", 'plan' => ''], 1, 25);
        self::assertSame('all', $invalid['filters']['status']);
        self::assertSame(3, $invalid['total']);

        $injection = $repository->paginateUsers(['q' => "%' OR 1=1 --"], 1, 25);
        self::assertSame(0, $injection['total']);
    }

    public function testAnalysisRejectionLogsOnlyNumericIdsAndEnumReasonOnWriteAndRead(): void
    {
        $pdo = AdminTestDatabase::create();
        $logs = new SystemLogRepository($pdo);
        $safe = ['job_id' => 51, 'project_id' => 11, 'analysis_id' => 31, 'validation_attempt' => 2, 'reason_code' => 'invalid_shape'];
        $unsafe = ['reason' => 'SECRET', 'model' => 'SECRET', 'raw' => 'SECRET', 'url' => 'https://private.invalid', 'exception_message' => 'SECRET'];
        self::assertTrue($logs->tryRecord('warning', 'ai.validation_rejected', $safe + $unsafe, null, 'job', 51));
        $row = $pdo->query('SELECT * FROM system_logs')->fetch();
        self::assertSame('ai.validation_rejected', $row['event_code']);
        self::assertSame('Resposta de análise rejeitada pela validação local.', $row['public_message']);
        self::assertSame($safe, json_decode($row['context_json'], true, 512, JSON_THROW_ON_ERROR));

        $pdo->prepare('UPDATE system_logs SET context_json = ?')->execute([json_encode($safe + $unsafe, JSON_THROW_ON_ERROR)]);
        $page = $logs->paginate(['event' => 'ai.validation_rejected'], 1);
        self::assertSame(1, $page['total']);
        self::assertSame($safe, $page['items'][0]['context']);

        $logs->record('warning', 'ai.validation_rejected', [
            'job_id' => 'SECRET', 'project_id' => true, 'analysis_id' => -1,
            'validation_attempt' => '2', 'reason_code' => 'SECRET', 'reason' => 'SECRET',
        ]);
        $page = $logs->paginate(['event' => 'ai.validation_rejected'], 1);
        self::assertSame([], $page['items'][0]['context']);
        self::assertStringNotContainsString('SECRET', json_encode($page, JSON_THROW_ON_ERROR));
        $row = $pdo->query('SELECT context_json FROM system_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
        self::assertSame([], json_decode($row, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSystemLogsDropSecretsAndOnlyReturnFixedPublicMessages(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $logs = new SystemLogRepository($pdo);

        $logs->record('warning', 'admin.user_suspended', [
            'reason' => 'Solicitação do suporte',
            'status' => 'suspended',
            'api_key' => 'foreign-secret',
            'private_path' => '/srv/private/video.mp4',
            'payload_json' => '{"secret":true}',
        ], $ids['admin_id'], 'user', $ids['user_id']);

        $page = $logs->paginate(['level' => 'warning'], 1, 25);
        self::assertSame(1, $page['total']);
        self::assertSame('Usuário suspenso por um administrador.', $page['items'][0]['message']);
        self::assertSame('Solicitação do suporte', $page['items'][0]['context']['reason']);
        $encoded = json_encode($page, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('foreign-secret', $encoded);
        self::assertStringNotContainsString('/srv/private', $encoded);
        self::assertStringNotContainsString('payload_json', $encoded);
    }
}
