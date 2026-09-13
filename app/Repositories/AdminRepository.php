<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AdminRepository
{
    private const USER_STATUSES = ['active', 'suspended'];
    private const PROJECT_STATUSES = ['draft', 'uploading', 'queued', 'processing', 'completed', 'failed'];
    private const JOB_STATUSES = ['queued', 'running', 'retry', 'completed', 'failed'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findIdentity(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT id, name, email, role, status FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $newUsersSql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'SELECT COUNT(*) FROM users WHERE created_at >= (CURRENT_TIMESTAMP - INTERVAL 30 DAY)'
            : "SELECT COUNT(*) FROM users WHERE created_at >= datetime('now', '-30 day')";
        return [
            'users_total' => $this->scalar('SELECT COUNT(*) FROM users'),
            'users_active' => $this->scalar("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'users_suspended' => $this->scalar("SELECT COUNT(*) FROM users WHERE status = 'suspended'"),
            'users_new' => $this->scalar($newUsersSql),
            'projects_total' => $this->scalar('SELECT COUNT(*) FROM projects'),
            'projects_processing' => $this->scalar("SELECT COUNT(*) FROM projects WHERE status IN ('queued', 'processing')"),
            'videos_processed' => $this->scalar("SELECT COUNT(*) FROM projects WHERE status IN ('suggestions_ready', 'completed')"),
            'storage_bytes' => $this->scalar('SELECT COALESCE(SUM(storage_bytes), 0) FROM projects'),
            'jobs_queued' => $this->scalar("SELECT COUNT(*) FROM processing_jobs WHERE status IN ('queued', 'retry')"),
            'jobs_running' => $this->scalar("SELECT COUNT(*) FROM processing_jobs WHERE status = 'running'"),
            'jobs_failed' => $this->scalar("SELECT COUNT(*) FROM processing_jobs WHERE status = 'failed'"),
            'credits_balance' => $this->scalar('SELECT COALESCE(SUM(credits), 0) FROM users'),
            'clips_total' => $this->scalar('SELECT COUNT(*) FROM clips'),
            'clips_completed' => $this->scalar("SELECT COUNT(*) FROM clips WHERE status = 'completed'"),
            'plans_in_use' => $this->scalar('SELECT COUNT(DISTINCT plan_id) FROM users'),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function paginateUsers(array $filters, int $page, int $perPage = 25): array
    {
        $normalized = $this->userFilters($filters);
        $where = [];
        $params = [];
        if ($normalized['status'] !== 'all') {
            $where[] = 'u.status = :status';
            $params['status'] = $normalized['status'];
        }
        if ($normalized['plan'] !== '') {
            $where[] = 'u.plan_id = :plan_id';
            $params['plan_id'] = (int) $normalized['plan'];
        }
        if ($normalized['q'] !== '') {
            $where[] = "(u.name LIKE :q_name ESCAPE '!' OR u.email LIKE :q_email ESCAPE '!')";
            $params['q_name'] = $params['q_email'] = '%' . $this->escapeLike($normalized['q']) . '%';
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        return $this->paginateQuery(
            'SELECT COUNT(*) FROM users u' . $clause,
            'SELECT u.id, u.name, u.email, u.role, u.status, u.plan_id, p.name AS plan_name,
                    COALESCE((SELECT ct.balance_after FROM credit_transactions ct WHERE ct.user_id = u.id ORDER BY ct.created_at DESC, ct.id DESC LIMIT 1), u.credits) AS credits,
                    u.created_at
             FROM users u INNER JOIN plans p ON p.id = u.plan_id' . $clause . ' ORDER BY u.id DESC',
            $params,
            $page,
            ['filters' => $normalized]
        );
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function paginateProjects(array $filters, int $page, int $perPage = 25): array
    {
        $status = is_string($filters['status'] ?? null) && in_array($filters['status'], self::PROJECT_STATUSES, true) ? $filters['status'] : 'all';
        $q = $this->search($filters['q'] ?? '');
        $where = [];
        $params = [];
        if ($status !== 'all') {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = "(p.name LIKE :q_name ESCAPE '!' OR u.email LIKE :q_email ESCAPE '!')";
            $params['q_name'] = $params['q_email'] = '%' . $this->escapeLike($q) . '%';
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = ' FROM projects p INNER JOIN users u ON u.id = p.user_id LEFT JOIN project_sources s ON s.project_id = p.id';

        return $this->paginateQuery(
            'SELECT COUNT(*)' . $from . $clause,
            'SELECT p.id, p.name, p.status, p.progress, p.original_duration_seconds, p.processed_duration_seconds,
                    p.storage_bytes, p.created_at, p.updated_at, u.id AS user_id, u.name AS user_name, u.email AS user_email,
                    s.source_type, s.original_name, s.mime_type, s.size_bytes, s.duration_seconds, s.status AS source_status'
                . $from . $clause . ' ORDER BY p.id DESC',
            $params,
            $page,
            ['filters' => ['status' => $status, 'q' => $q]]
        );
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function paginateVideos(array $filters, int $page, int $perPage = 25): array
    {
        $source = is_string($filters['source'] ?? null) && in_array($filters['source'], ['upload', 'direct_url'], true) ? $filters['source'] : 'all';
        $status = is_string($filters['status'] ?? null) && in_array($filters['status'], ['pending', 'stored', 'ready', 'failed'], true) ? $filters['status'] : 'all';
        $q = $this->search($filters['q'] ?? '');
        $where = [];
        $params = [];
        if ($source !== 'all') {
            $where[] = 's.source_type = :source';
            $params['source'] = $source;
        }
        if ($status !== 'all') {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = "(p.name LIKE :q_name ESCAPE '!' OR u.email LIKE :q_email ESCAPE '!' OR s.original_name LIKE :q_source ESCAPE '!')";
            $params['q_name'] = $params['q_email'] = $params['q_source'] = '%' . $this->escapeLike($q) . '%';
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = ' FROM project_sources s INNER JOIN projects p ON p.id = s.project_id INNER JOIN users u ON u.id = p.user_id';

        return $this->paginateQuery(
            'SELECT COUNT(*)' . $from . $clause,
            'SELECT s.id, s.project_id, s.source_type, s.original_name, s.mime_type, s.size_bytes, s.duration_seconds,
                    s.width, s.height, s.video_codec, s.audio_codec, s.has_audio, s.status, s.created_at,
                    p.name AS project_name, u.id AS user_id, u.email AS user_email'
                . $from . $clause . ' ORDER BY s.id DESC',
            $params,
            $page,
            ['filters' => ['source' => $source, 'status' => $status, 'q' => $q]]
        );
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function paginateJobs(array $filters, int $page, int $perPage = 25, bool $errorsOnly = false): array
    {
        $status = is_string($filters['status'] ?? null) && in_array($filters['status'], self::JOB_STATUSES, true) ? $filters['status'] : 'all';
        $type = is_string($filters['type'] ?? null) && preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $filters['type']) === 1 ? $filters['type'] : '';
        $where = [];
        $params = [];
        if ($errorsOnly) {
            $where[] = "j.status = 'failed'";
            $status = 'failed';
        } elseif ($status !== 'all') {
            $where[] = 'j.status = :status';
            $params['status'] = $status;
        }
        if ($type !== '') {
            $where[] = 'j.type = :type';
            $params['type'] = $type;
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = ' FROM processing_jobs j INNER JOIN projects p ON p.id = j.project_id INNER JOIN users u ON u.id = p.user_id';

        return $this->paginateQuery(
            'SELECT COUNT(*)' . $from . $clause,
            'SELECT j.id, j.queue_name, j.type, j.project_id, j.status, j.progress, j.attempts, j.max_attempts,
                    j.available_at, j.started_at, j.finished_at, j.last_error_code, j.created_at, j.updated_at,
                    p.name AS project_name, u.id AS user_id, u.email AS user_email'
                . $from . $clause . ' ORDER BY j.id DESC',
            $params,
            $page,
            ['filters' => ['status' => $status, 'type' => $type], 'errors_only' => $errorsOnly]
        );
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function paginateCredits(array $filters, int $page, int $perPage = 25): array
    {
        $q = $this->search($filters['q'] ?? '');
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = "(u.name LIKE :q_name ESCAPE '!' OR u.email LIKE :q_email ESCAPE '!')";
            $params['q_name'] = $params['q_email'] = '%' . $this->escapeLike($q) . '%';
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = ' FROM credit_transactions c INNER JOIN users u ON u.id = c.user_id';

        return $this->paginateQuery(
            'SELECT COUNT(*)' . $from . $clause,
            'SELECT c.id, c.user_id, c.type, c.amount, c.balance_after, c.reference_type, c.description, c.created_at,
                    u.name AS user_name, u.email AS user_email'
                . $from . $clause . ' ORDER BY c.id DESC',
            $params,
            $page,
            ['filters' => ['q' => $q]]
        );
    }

    /** @return list<array<string,mixed>> */
    public function plans(bool $activeOnly = false): array
    {
        $statement = $this->pdo->query(
            'SELECT id, slug, name, price_cents, monthly_minutes, credits, features, is_active, created_at, updated_at
             FROM plans' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY price_cents ASC, id ASC'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function userDetail(int $userId): ?array
    {
        $user = $this->pdo->prepare('SELECT u.id,u.name,u.email,u.role,u.status,u.archived_at,u.plan_id,u.credits,u.created_at,p.name AS plan_name FROM users u INNER JOIN plans p ON p.id=u.plan_id WHERE u.id=:id LIMIT 1');
        $user->execute(['id' => $userId]); $row = $user->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $projects = $this->pdo->prepare('SELECT id,name,status,progress,storage_bytes,updated_at FROM projects WHERE user_id=:id ORDER BY id DESC LIMIT 10');
        $projects->execute(['id' => $userId]);
        $ledger = $this->pdo->prepare('SELECT type,amount,balance_after,description,created_at FROM credit_transactions WHERE user_id=:id ORDER BY id DESC LIMIT 20');
        $ledger->execute(['id' => $userId]);
        $activity = $this->pdo->prepare("SELECT event_code,public_message,created_at FROM system_logs WHERE target_type='user' AND target_id=:id ORDER BY id DESC LIMIT 20");
        $activity->execute(['id' => $userId]);
        $row['projects'] = $projects->fetchAll(PDO::FETCH_ASSOC); $row['ledger'] = $ledger->fetchAll(PDO::FETCH_ASSOC); $row['activity'] = $activity->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function createUser(string $name, string $email, string $hash, int $planId, string $role): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name,email,password_hash,plan_id,credits,role,status) VALUES (:name,:email,:hash,:plan,0,:role,\'active\')');
        $statement->execute(['name'=>$name,'email'=>$email,'hash'=>$hash,'plan'=>$planId,'role'=>$role]); return (int) $this->pdo->lastInsertId();
    }

    public function updateUserProfile(int $userId, string $name, string $email, string $role): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET name=:name,email=:email,role=:role,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute(['name'=>$name,'email'=>$email,'role'=>$role,'id'=>$userId]);
    }

    public function emailTaken(string $email, int $except): bool
    {
        $statement=$this->pdo->prepare('SELECT id FROM users WHERE email=:email AND id<>:id LIMIT 1'); $statement->execute(['email'=>$email,'id'=>$except]); return $statement->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function lockUser(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, plan_id, credits, role, status FROM users WHERE id = :id LIMIT 1' . $this->lockClause()
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function lockPlan(int $planId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, slug, name, price_cents, monthly_minutes, credits, features, is_active FROM plans WHERE id = :id LIMIT 1' . $this->lockClause()
        );
        $statement->execute(['id' => $planId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function activeAdminCount(): int
    {
        return $this->scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
    }

    public function setUserStatus(int $userId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id' . ($status === 'active' ? ' AND archived_at IS NULL' : ''));
        $statement->execute(['status' => $status, 'id' => $userId]);
        if ($status === 'active' && $statement->rowCount() === 0) {
            $archived = $this->pdo->prepare('SELECT archived_at FROM users WHERE id=:id');
            $archived->execute(['id'=>$userId]);
            $value=$archived->fetchColumn();
            if ($value !== false && $value !== null) throw new \DomainException('Restaure explicitamente a conta arquivada antes de ativá-la.');
        }
    }

    /** Bounded production BRL history; no provider identifiers or raw snapshots. */
    public function userBillingHistory(int $userId): array
    {
        $payments=$this->pdo->prepare("SELECT b.id,b.provider,b.status,b.currency,b.paid_amount_cents,b.refunded_amount_cents,b.paid_at,b.created_at,p.name AS plan_name FROM billing_payments b LEFT JOIN plans p ON p.id=b.plan_id WHERE b.user_id=:id AND b.environment='production' AND b.currency='BRL' ORDER BY b.id DESC LIMIT 10");
        $payments->execute(['id'=>$userId]);
        $subscriptions=$this->pdo->prepare("SELECT b.id,b.provider,b.status,b.currency,b.amount_cents,b.current_period_ends_at,b.created_at,p.name AS plan_name FROM billing_subscriptions b LEFT JOIN plans p ON p.id=b.plan_id WHERE b.user_id=:id AND b.environment='production' AND b.currency='BRL' ORDER BY b.id DESC LIMIT 10");
        $subscriptions->execute(['id'=>$userId]);
        return ['payments'=>$payments->fetchAll(PDO::FETCH_ASSOC),'subscriptions'=>$subscriptions->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function archiveUser(int $userId): void
    {
        $statement = $this->pdo->prepare("UPDATE users SET status = 'suspended', archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $statement->execute(['id' => $userId]);
    }

    public function restoreUser(int $userId): void
    {
        $statement = $this->pdo->prepare("UPDATE users SET status = 'active', archived_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $statement->execute(['id' => $userId]);
    }

    public function setUserPlan(int $userId, int $planId): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET plan_id = :plan_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute(['plan_id' => $planId, 'id' => $userId]);
    }

    public function latestBalanceForUpdate(int $userId, int $fallback): int
    {
        $statement = $this->pdo->prepare(
            'SELECT balance_after FROM credit_transactions WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 1' . $this->lockClause()
        );
        $statement->execute(['user_id' => $userId]);
        $value = $statement->fetchColumn();

        return $value === false ? $fallback : (int) $value;
    }

    public function setUserCredits(int $userId, int $balance): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET credits = :balance, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute(['balance' => $balance, 'id' => $userId]);
    }

    public function addCreditAdjustment(int $userId, int $amount, int $balance, string $reason): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, reference_id, description)
             VALUES (:user_id, 'adjustment', :amount, :balance, 'admin_adjustment', NULL, :description)"
        );
        $statement->execute(['user_id' => $userId, 'amount' => $amount, 'balance' => $balance, 'description' => $reason]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,int|string> $values */
    public function updatePlan(int $planId, array $values): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE plans SET name = :name, price_cents = :price_cents, monthly_minutes = :monthly_minutes,
                    credits = :credits, features = :features, is_active = :is_active, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'name' => $values['name'],
            'price_cents' => $values['price_cents'],
            'monthly_minutes' => $values['monthly_minutes'],
            'credits' => $values['credits'],
            'features' => $values['features'],
            'is_active' => $values['is_active'],
            'id' => $planId,
        ]);
    }

    /** @param array<string,int|string> $values */
    public function createPlan(array $values): int
    {
        $statement=$this->pdo->prepare('INSERT INTO plans (slug,name,price_cents,monthly_minutes,credits,features,is_active) VALUES (:slug,:name,:price_cents,:monthly_minutes,:credits,:features,:is_active)');
        $statement->execute($values); return (int)$this->pdo->lastInsertId();
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /** @return list<array{event_code:string,public_message:string,created_at:string}> */
    private function recentActivity(): array
    {
        return $this->pdo->query('SELECT event_code, public_message, created_at FROM system_logs ORDER BY id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $extra @return array<string,mixed> */
    private function paginateQuery(string $countSql, string $selectSql, array $params, int $page, array $extra): array
    {
        $perPage = 25;
        $count = $this->pdo->prepare($countSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $select = $this->pdo->prepare($selectSql . ' LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) {
            $select->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $select->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $select->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $select->execute();

        return $extra + [
            'items' => $select->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ];
    }

    /** @param array<string,mixed> $filters @return array{q:string,status:string,plan:string} */
    private function userFilters(array $filters): array
    {
        $status = is_string($filters['status'] ?? null) && in_array($filters['status'], self::USER_STATUSES, true) ? $filters['status'] : 'all';
        $plan = '';
        if (is_int($filters['plan'] ?? null) && $filters['plan'] > 0) {
            $plan = (string) $filters['plan'];
        } elseif (is_string($filters['plan'] ?? null) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $filters['plan']) === 1) {
            $plan = $filters['plan'];
        }

        return ['q' => $this->search($filters['q'] ?? ''), 'status' => $status, 'plan' => $plan];
    }

    private function search(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    private function lockClause(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
