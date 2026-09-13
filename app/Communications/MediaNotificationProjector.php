<?php
declare(strict_types=1);

namespace App\Communications;

use App\Contracts\CommunicationEmitter;
use PDO;
use Throwable;

/** Reads media state; writes only notification projection/checkpoint/outbox tables. */
final class MediaNotificationProjector
{
    private const SOURCES = ['projects' => 'projects', 'clips' => 'clips', 'jobs' => 'processing_jobs'];
    private const QUOTA_ERRORS = ['insufficient_credits','monthly_minutes_exceeded','storage_limit_exceeded','upload_limit_exceeded'];

    public function __construct(private PDO $pdo, private CommunicationEmitter $emitter) {}

    /** Capture a permanent initial high-water, without emitting or resetting existing checkpoints. */
    public function initialize(): array
    {
        $this->requireOwnTransaction();
        $this->pdo->beginTransaction();
        try {
            $watermarks = [];
            foreach (self::SOURCES as $source => $table) {
                $read = $this->pdo->prepare('SELECT high_water_id FROM communication_media_checkpoints WHERE source=:source' . $this->lockClause());
                $read->execute(['source' => $source]);
                $high = $read->fetchColumn();
                if ($high === false) {
                    $high = (int) $this->pdo->query('SELECT COALESCE(MAX(id),0) FROM ' . $table)->fetchColumn();
                    $insert = $this->pdo->prepare('INSERT INTO communication_media_checkpoints(source,high_water_id,cursor_id,initialized_at) VALUES(:source,:high,0,CURRENT_TIMESTAMP)');
                    $insert->execute(['source' => $source, 'high' => $high]);
                }
                $watermarks[$source] = (int) $high;
            }
            $this->pdo->commit();
            return $watermarks;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw new \RuntimeException('Não foi possível inicializar os checkpoints de mídia.', 0, $e);
        }
    }

    /** One bounded keyset page. Full cycles detect changes without relying on second-resolution timestamps. */
    public function runBatch(string $source, int $limit = 25): array
    {
        if (!isset(self::SOURCES[$source]) || $limit < 1 || $limit > 100) throw new \InvalidArgumentException('Fonte inválida ou lote fora de 1 a 100.');
        $this->requireOwnTransaction();
        $this->pdo->beginTransaction();
        try {
            $read = $this->pdo->prepare('SELECT high_water_id,cursor_id FROM communication_media_checkpoints WHERE source=:source' . $this->lockClause());
            $read->execute(['source' => $source]);
            $checkpoint = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($checkpoint)) throw new \LogicException('Inicialize o projetor antes de processar novos eventos.');
            $rows = $this->page($source, (int) $checkpoint['cursor_id'], $limit);
            $counts = ['source' => $source, 'scanned' => count($rows), 'emitted' => 0, 'baseline' => 0, 'suppressed' => 0, 'cursor' => 0];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $seen = $this->pdo->prepare('SELECT 1 FROM communication_media_observations WHERE source=:source AND entity_id=:id');
                $seen->execute(['source' => $source, 'id' => $id]);
                $baseline = $seen->fetchColumn() === false && $id <= (int) $checkpoint['high_water_id'];
                $signal = $this->signal($source, $row);
                if ($signal !== null) {
                    [$event, $revision, $eligible] = $signal;
                    $key = 'media:' . $source . ':' . $id . ':' . $revision . ':' . substr($event, 5);
                    $receipt = $this->pdo->prepare('SELECT 1 FROM communication_media_receipts WHERE dedupe_key=:key');
                    $receipt->execute(['key' => $key]);
                    if ($receipt->fetchColumn() === false) {
                        $category = $event === 'media.usage_limit_reached' ? 'usage' : 'processing';
                        $channels = $this->channels((int) $row['user_id'], $category);
                        $disposition = $baseline ? 'baseline' : (($eligible && $row['user_status'] === 'active' && $channels !== []) ? 'emitted' : 'suppressed');
                        if ($disposition === 'emitted') {
                            $variables = ['nome_usuario' => (string) $row['user_name']];
                            if ($category === 'usage') $variables['nome_plano'] = (string) $row['plan_name'];
                            else $variables['nome_projeto'] = (string) $row['project_name'];
                            $this->emitter->emit((int) $row['user_id'], $event, $variables, $key, null, $channels);
                        }
                        $insert = $this->pdo->prepare('INSERT INTO communication_media_receipts(dedupe_key,source,entity_id,event,disposition) VALUES(:key,:source,:id,:event,:disposition)');
                        $insert->execute(compact('key','source','id','event','disposition'));
                        ++$counts[$disposition];
                    }
                }
                $this->observe($source, $row);
                $counts['cursor'] = $id;
            }
            if (count($rows) < $limit) $counts['cursor'] = 0;
            $update = $this->pdo->prepare('UPDATE communication_media_checkpoints SET cursor_id=:cursor,last_run_at=CURRENT_TIMESTAMP,last_error_code=NULL WHERE source=:source');
            $update->execute(['cursor' => $counts['cursor'], 'source' => $source]);
            $this->pdo->commit();
            return $counts;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            // A fixed operational code is visible without logging media names, payloads or tokens.
            try {
                $error = $this->pdo->prepare("UPDATE communication_media_checkpoints SET last_error_code='projection_failed' WHERE source=:source");
                $error->execute(['source' => $source]);
            } catch (Throwable) {}
            if ($e instanceof \LogicException) throw $e;
            throw new \RuntimeException('Falha na projeção de notificações de mídia (' . $source . '); lote não confirmado.', 0, $e);
        }
    }

    private function page(string $source, int $cursor, int $limit): array
    {
        $common = 'p.user_id,p.name AS project_name,p.status AS project_status,u.name AS user_name,u.status AS user_status,pl.name AS plan_name,u.plan_id';
        $joins = ' INNER JOIN users u ON u.id=p.user_id INNER JOIN plans pl ON pl.id=u.plan_id';
        $latest = 'COALESCE((SELECT MAX(a.id) FROM ai_analyses a WHERE a.project_id=p.id),0)';
        $sql = match ($source) {
            'projects' => 'SELECT p.id,p.status,p.error_code,' . $latest . ' AS revision,' . $common . ' FROM projects p' . $joins . ' WHERE p.id>:cursor ORDER BY p.id',
            'clips' => 'SELECT c.id,c.status,c.render_error_code AS error_code,c.render_revision AS revision,c.ai_analysis_id,' . $latest . ' AS latest_analysis,' . $common . ' FROM clips c INNER JOIN projects p ON p.id=c.project_id' . $joins . ' WHERE c.id>:cursor ORDER BY c.id',
            'jobs' => 'SELECT j.id,j.status,j.last_error_code AS error_code,j.attempts AS revision,j.type,j.queue_name,' . $common . ' FROM processing_jobs j INNER JOIN projects p ON p.id=j.project_id' . $joins . ' WHERE j.id>:cursor ORDER BY j.id',
        };
        $statement = $this->pdo->prepare($sql . ' LIMIT ' . $limit);
        $statement->execute(['cursor' => $cursor]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{string,string,bool}|null */
    private function signal(string $source, array $row): ?array
    {
        $status = (string) $row['status'];
        $revision = (string) max(0, (int) $row['revision']);
        $eligible = true;
        if ($source === 'jobs') {
            if ($status !== 'failed' || $row['queue_name'] !== 'media') return null;
            // Parent/clip terminal state has the authoritative outcome and its own projector.
            $eligible = $row['project_status'] !== 'failed' && $row['type'] !== 'render_clip';
        }
        if ($source === 'clips') $eligible = (int) $row['ai_analysis_id'] === (int) $row['latest_analysis'] && (int) $row['revision'] > 0;
        if (in_array((string) $row['error_code'], self::QUOTA_ERRORS, true) && in_array($status, ['failed','awaiting_credits'], true)) {
            return ['media.usage_limit_reached', $revision . ':plan:' . (int) $row['plan_id'], $eligible];
        }
        if ($status === 'failed') return ['media.processing_failed', $revision, $eligible];
        if ($source !== 'jobs' && in_array($status, ['completed','suggestions_ready'], true)) return ['media.processing_completed', $revision, $eligible];
        return null;
    }

    private function channels(int $userId, string $category): array
    {
        $s = $this->pdo->prepare('SELECT email_enabled,in_app_enabled FROM communication_preferences WHERE user_id=:user AND category=:category');
        $s->execute(['user' => $userId, 'category' => $category]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return ['in_app','email'];
        $channels = [];
        if ((int) $row['in_app_enabled'] === 1) $channels[] = 'in_app';
        if ((int) $row['email_enabled'] === 1) $channels[] = 'email';
        return $channels;
    }

    private function observe(string $source, array $row): void
    {
        $sql = 'INSERT INTO communication_media_observations(source,entity_id,last_state,last_revision,observed_at) VALUES(:source,:id,:state,:revision,CURRENT_TIMESTAMP)';
        $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE last_state=VALUES(last_state),last_revision=VALUES(last_revision),observed_at=CURRENT_TIMESTAMP'
            : ' ON CONFLICT(source,entity_id) DO UPDATE SET last_state=excluded.last_state,last_revision=excluded.last_revision,observed_at=CURRENT_TIMESTAMP';
        $this->pdo->prepare($sql)->execute(['source' => $source, 'id' => $row['id'], 'state' => $row['status'], 'revision' => (string) $row['revision']]);
    }

    private function lockClause(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function requireOwnTransaction(): void { if ($this->pdo->inTransaction()) throw new \LogicException('Execute o projetor fora de transações externas.'); }
}
