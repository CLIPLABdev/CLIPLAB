<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Ai\InvalidAnalysisResponse;
use InvalidArgumentException;
use PDO;
use Throwable;

final class SystemLogRepository
{
    /** @var array<string,string> */
    private const EVENTS = [
        'admin.user_suspended' => 'Usuário suspenso por um administrador.',
        'admin.user_reactivated' => 'Usuário reativado por um administrador.',
        'admin.plan_assigned' => 'Plano de usuário alterado por um administrador.',
        'admin.credits_adjusted' => 'Saldo de créditos ajustado por um administrador.',
        'admin.plan_updated' => 'Plano atualizado por um administrador.',
        'admin.gemini_settings_updated' => 'Configuração do Gemini atualizada por um administrador.',
        'admin.gemini_connection_tested' => 'Conexão com o Gemini testada por um administrador.',
        'admin.account_created' => 'Conta administrativa criada pela linha de comando.',
        'admin.account_promoted' => 'Conta promovida a administrador pela linha de comando.',
        'admin.user_created' => 'Usuário criado no painel administrativo.',
        'admin.user_updated' => 'Dados do usuário atualizados por um administrador.',
        'admin.user_role_changed' => 'Permissões do usuário alteradas por um administrador.',
        'queue.completed' => 'Processamento concluído.',
        'queue.failed' => 'Processamento falhou.',
        'queue.retry' => 'Processamento aguardando nova tentativa.',
        'ai.validation_rejected' => 'Resposta de análise rejeitada pela validação local.',
        'ai.provider_failure' => 'Falha de comunicação com o provedor de análise de IA.',
        'youtube.import_diagnostic' => 'Diagnóstico da consulta de importação do YouTube.',
        'youtube.download_diagnostic' => 'Diagnóstico do download do vídeo do YouTube.',
        'opusclip.raw_clip' => 'Corte recebido da OpusClip.',
        'system.operation_failed' => 'Uma operação interna não pôde ser concluída.',
    ];

    private const LEVELS = ['info', 'warning', 'error'];
    private const CONTEXT_KEYS = [
        'reason', 'status', 'plan_id', 'amount', 'balance', 'model', 'result_code', 'source',
        'job_id', 'project_id', 'job_type', 'correlation_id',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $context */
    public function record(
        string $level,
        string $eventCode,
        array $context = [],
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null
    ): void {
        $level = in_array($level, self::LEVELS, true) ? $level : 'error';
        if (!array_key_exists($eventCode, self::EVENTS)) {
            $eventCode = 'system.operation_failed';
        }
        $targetType = in_array($targetType, ['user', 'plan', 'gemini_settings', 'job', 'project'], true) ? $targetType : null;
        $statement = $this->pdo->prepare(
            'INSERT INTO system_logs (level, event_code, public_message, context_json, actor_id, target_type, target_id)
             VALUES (:level, :event_code, :public_message, :context_json, :actor_id, :target_type, :target_id)'
        );
        $statement->execute([
            'level' => $level,
            'event_code' => $eventCode,
            'public_message' => self::EVENTS[$eventCode],
            'context_json' => json_encode($this->sanitizeContext($context, $eventCode), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actor_id' => $actorId !== null && $actorId > 0 ? $actorId : null,
            'target_type' => $targetType,
            'target_id' => $targetId !== null && $targetId > 0 ? $targetId : null,
        ]);
    }

    /** @param array<string,mixed> $context */
    public function tryRecord(
        string $level,
        string $eventCode,
        array $context = [],
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null
    ): bool {
        try {
            $this->record($level, $eventCode, $context, $actorId, $targetType, $targetId);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,filters:array{level:string,event:string},page:int,per_page:int,total:int,last_page:int} */
    public function paginate(array $filters, int $page, int $perPage = 25): array
    {
        $level = is_string($filters['level'] ?? null) && in_array($filters['level'], self::LEVELS, true) ? $filters['level'] : 'all';
        $event = is_string($filters['event'] ?? null) && array_key_exists($filters['event'], self::EVENTS) ? $filters['event'] : '';
        $page = max(1, $page);
        $perPage = 25;
        $where = [];
        $params = [];
        if ($level !== 'all') {
            $where[] = 'level = :level';
            $params['level'] = $level;
        }
        if ($event !== '') {
            $where[] = 'event_code = :event';
            $params['event'] = $event;
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM system_logs' . $clause);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $query = $this->pdo->prepare(
            'SELECT id, level, event_code, context_json, actor_id, target_type, target_id, created_at
             FROM system_logs' . $clause . ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $query->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $query->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $query->execute();
        $items = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $eventCode = is_string($row['event_code'] ?? null) && isset(self::EVENTS[$row['event_code']])
                ? $row['event_code']
                : 'system.operation_failed';
            $decoded = json_decode((string) ($row['context_json'] ?? '{}'), true);
            $items[] = [
                'id' => (int) $row['id'],
                'level' => in_array($row['level'] ?? null, self::LEVELS, true) ? $row['level'] : 'error',
                'event_code' => $eventCode,
                'message' => self::EVENTS[$eventCode],
                'context' => $this->sanitizeContext(is_array($decoded) ? $decoded : [], $eventCode),
                'actor_id' => isset($row['actor_id']) ? (int) $row['actor_id'] : null,
                'target_type' => in_array($row['target_type'] ?? null, ['user', 'plan', 'gemini_settings', 'job', 'project'], true) ? $row['target_type'] : null,
                'target_id' => isset($row['target_id']) ? (int) $row['target_id'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return ['items' => $items, 'filters' => ['level' => $level, 'event' => $event], 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage];
    }

    /** @param array<string,mixed> $context @return array<string,int|string|bool> */
    private function sanitizeContext(array $context, string $eventCode): array
    {
        if ($eventCode === 'ai.provider_failure') {
            $safe = [];
            foreach (['job_id','project_id','analysis_id'] as $key) {
                if (is_int($context[$key] ?? null) && $context[$key] > 0) $safe[$key] = $context[$key];
            }
            foreach (['attempt'=>[1,100], 'max_attempts'=>[1,100], 'http_status'=>[100,599], 'curl_errno'=>[0,999], 'retry_after_seconds'=>[1,900]] as $key=>$range) {
                if (is_int($context[$key] ?? null) && $context[$key] >= $range[0] && $context[$key] <= $range[1]) $safe[$key] = $context[$key];
            }
            foreach (['phase'=>['upload','poll','generate','analysis'], 'failure_kind'=>['http','transport','internal'], 'result_code'=>['ai_unconfigured','ai_timeout','ai_rate_limited','ai_unavailable','ai_provider_rejected']] as $key=>$allowed) {
                if (in_array($context[$key] ?? null, $allowed, true)) $safe[$key] = $context[$key];
            }
            return $safe;
        }
        if ($eventCode === 'youtube.import_diagnostic') {
            $safe = [];
            if (is_string($context['video_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $context['video_id'])) {
                $safe['video_id'] = $context['video_id'];
            }
            $codes = ['metadata_validated', 'youtube_video_unavailable', 'youtube_response_invalid',
                'youtube_metadata_limit', 'youtube_bot_challenge', 'youtube_age_restricted',
                'youtube_region_restricted', 'youtube_private_video', 'youtube_login_required',
                'youtube_format_unavailable', 'youtube_rate_limited', 'youtube_network_failed',
                'youtube_import_unavailable', 'remote_timeout', 'media_too_large', 'unsafe_source_url'];
            if (in_array($context['result_code'] ?? null, $codes, true)) {
                $safe['result_code'] = $context['result_code'];
            }
            foreach (['exit_code', 'output_bytes', 'limit_bytes'] as $key) {
                if (is_int($context[$key] ?? null) && $context[$key] >= 0) {
                    $safe[$key] = $context[$key];
                }
            }
            return $safe;
        }
        if ($eventCode === 'youtube.download_diagnostic') {
            $safe = [];
            if (in_array($context['result'] ?? null, ['downloaded', 'failed', 'no_output'], true)) {
                $safe['result'] = $context['result'];
            }
            foreach (['exit_code', 'bytes', 'seconds'] as $key) {
                if (is_int($context[$key] ?? null) && $context[$key] >= 0) {
                    $safe[$key] = $context[$key];
                }
            }
            if (is_string($context['error'] ?? null) && $context['error'] !== '') {
                $safe['error'] = mb_substr((string) preg_replace(['#https?://\S+#', '/[\x00-\x1F\x7F]/u'], ['[url]', ''], $context['error']), 0, 300);
            }
            return $safe;
        }
        if ($eventCode === 'opusclip.raw_clip') {
            $safe = [];
            foreach (['project_id', 'analysis_id'] as $key) {
                if (is_int($context[$key] ?? null) && $context[$key] > 0) {
                    $safe[$key] = $context[$key];
                }
            }
            $raw = is_array($context['raw'] ?? null) ? $context['raw'] : [];
            $safe['fields'] = mb_substr(implode(',', array_filter(array_keys($raw), 'is_string')), 0, 300);
            if (is_numeric($raw['durationMs'] ?? null)) {
                $safe['duration_ms'] = (int) $raw['durationMs'];
            }
            if (array_key_exists('timeRanges', $raw)) {
                $safe['time_ranges'] = mb_substr((string) json_encode($raw['timeRanges'], JSON_UNESCAPED_SLASHES), 0, 300);
            }
            return $safe;
        }
        if ($eventCode === 'ai.validation_rejected') {
            return $this->sanitizeAnalysisRejection($context);
        }
        $safe = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $value = $context[$key] ?? null;
            if (is_bool($value) || is_int($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value) && $value !== '') {
                $safe[$key] = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '', 0, 200);
            }
        }

        return $safe;
    }

    /** @param array<string,mixed> $context @return array<string,int|string> */
    private function sanitizeAnalysisRejection(array $context): array
    {
        $safe = [];
        foreach (['job_id', 'project_id', 'analysis_id', 'validation_attempt'] as $key) {
            if (isset($context[$key]) && is_int($context[$key]) && $context[$key] > 0) {
                $safe[$key] = $context[$key];
            }
        }
        if (isset($context['reason_code']) && is_string($context['reason_code'])) {
            try {
                $safe['reason_code'] = (new InvalidAnalysisResponse($context['reason_code']))->reasonCode();
            } catch (InvalidArgumentException) {
                // Only the validator's finite reason-code vocabulary is loggable.
            }
        }

        return $safe;
    }
}
