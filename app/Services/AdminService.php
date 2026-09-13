<?php

declare(strict_types=1);

namespace App\Services;

use App\Plans\PlanLimits;
use App\Repositories\AdminRepository;
use App\Repositories\SystemLogRepository;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class AdminService
{
    private const MAX_BALANCE = 2147483647;
    private const MAX_ADJUSTMENT = 1000000000;

    public function __construct(
        private PDO $pdo,
        private AdminRepository $repository,
        private SystemLogRepository $logs
    ) {
    }

    public function changeUserStatus(int $actorId, int $targetId, string $status, string $reason): void
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('User status is invalid.');
        }
        $reason = $this->reason($reason);

        $this->transactional(function () use ($actorId, $targetId, $status, $reason): void {
            $this->assertActor($actorId);
            $target = $this->requiredUser($targetId);
            if ($status === 'suspended' && ($target['role'] ?? null) === 'admin' && $this->repository->activeAdminCount() <= 1) {
                throw new DomainException('The last active administrator cannot be suspended.');
            }
            if ($status === 'suspended' && $actorId === $targetId) {
                throw new DomainException('An administrator cannot suspend their own account.');
            }

            $this->repository->setUserStatus($targetId, $status);
            $event = $status === 'active' ? 'admin.user_reactivated' : 'admin.user_suspended';
            $this->logs->record('warning', $event, ['reason' => $reason, 'status' => $status], $actorId, 'user', $targetId);
        });
    }

    public function assignPlan(int $actorId, int $targetId, int $planId, string $reason): void
    {
        $reason = $this->reason($reason);
        $this->transactional(function () use ($actorId, $targetId, $planId, $reason): void {
            $this->assertActor($actorId);
            $this->requiredUser($targetId);
            $plan = $this->repository->lockPlan($planId);
            if (!is_array($plan) || (int) ($plan['is_active'] ?? 0) !== 1) {
                throw new DomainException('An active plan is required.');
            }

            $this->repository->setUserPlan($targetId, $planId);
            $this->logs->record('warning', 'admin.plan_assigned', ['reason' => $reason, 'plan_id' => $planId], $actorId, 'user', $targetId);
        });
    }

    public function archiveUser(int $actorId, int $targetId, string $reason): void
    {
        $reason = $this->reason($reason);
        $this->transactional(function () use ($actorId, $targetId, $reason): void {
            $this->assertActor($actorId);
            $target = $this->requiredUser($targetId);
            if ($actorId === $targetId) throw new DomainException('An administrator cannot archive their own account.');
            if (($target['role'] ?? null) === 'admin' && $this->repository->activeAdminCount() <= 1) throw new DomainException('The last active administrator cannot be archived.');
            $this->repository->archiveUser($targetId);
            $this->logs->record('warning', 'admin.user_suspended', ['reason' => $reason, 'status' => 'suspended'], $actorId, 'user', $targetId);
        });
    }

    public function restoreUser(int $actorId, int $targetId, string $reason): void
    {
        $reason = $this->reason($reason);
        $this->transactional(function () use ($actorId, $targetId, $reason): void {
            $this->assertActor($actorId);
            $this->requiredUser($targetId);
            $this->repository->restoreUser($targetId);
            $this->logs->record('warning', 'admin.user_reactivated', ['reason' => $reason, 'status' => 'active'], $actorId, 'user', $targetId);
        });
    }

    /** @param array<string,mixed> $input */
    public function createUser(int $actorId, array $input): int
    {
        return $this->transactional(function () use ($actorId, $input): int {
            $this->assertActor($actorId); $name=$this->userName($input['name'] ?? null); $email=$this->email($input['email'] ?? null);
            $password=$input['password'] ?? null; if (!is_string($password)||strlen($password)<12||strlen($password)>72||str_contains($password,"\0")) throw new InvalidArgumentException('Password is invalid.');
            $planId=(int)($input['plan_id'] ?? 0); $plan=$this->repository->lockPlan($planId); if (!is_array($plan)||(int)$plan['is_active']!==1) throw new DomainException('An active plan is required.');
            $role=$this->role($input['role'] ?? null); if ($this->repository->emailTaken($email, 0)) throw new DomainException('Email already exists.');
            $id=$this->repository->createUser($name,$email,password_hash($password,PASSWORD_DEFAULT),$planId,$role); $this->logs->record('warning','admin.user_created',['reason'=>$this->reason(is_string($input['reason']??null)?$input['reason']:'')],$actorId,'user',$id); return $id;
        });
    }

    /** @param array<string,mixed> $input */
    public function editUser(int $actorId, int $targetId, array $input): void
    {
        $this->transactional(function () use ($actorId,$targetId,$input): void {
            $this->assertActor($actorId); $target=$this->requiredUser($targetId); $role=$this->role($input['role']??null);
            if ($actorId===$targetId && $role!==($target['role']??'')) throw new DomainException('An administrator cannot change their own role.');
            if (($target['role']??'')==='admin' && $role!=='admin' && $this->repository->activeAdminCount()<=1) throw new DomainException('The last active administrator cannot be demoted.');
            $email=$this->email($input['email']??null); if ($this->repository->emailTaken($email,$targetId)) throw new DomainException('Email already exists.');
            $this->repository->updateUserProfile($targetId,$this->userName($input['name']??null),$email,$role);
            $event=$role===($target['role']??'')?'admin.user_updated':'admin.user_role_changed';
            $this->logs->record('warning',$event,['reason'=>$this->reason(is_string($input['reason']??null)?$input['reason']:'')],$actorId,'user',$targetId);
        });
    }

    public function adjustCredits(int $actorId, int $targetId, int $amount, string $reason): int
    {
        if ($amount === 0 || $amount < -self::MAX_ADJUSTMENT || $amount > self::MAX_ADJUSTMENT) {
            throw new DomainException('Credit adjustment is outside the allowed range.');
        }
        $reason = $this->reason($reason);

        return $this->transactional(function () use ($actorId, $targetId, $amount, $reason): int {
            $this->assertActor($actorId);
            $target = $this->requiredUser($targetId);
            $current = $this->repository->latestBalanceForUpdate($targetId, (int) ($target['credits'] ?? 0));
            if (($amount < 0 && $current < -$amount) || ($amount > 0 && $current > self::MAX_BALANCE - $amount)) {
                throw new DomainException('Credit adjustment would create an invalid balance.');
            }
            $balance = $current + $amount;
            if ($balance < 0 || $balance > self::MAX_BALANCE) {
                throw new DomainException('Credit adjustment would create an invalid balance.');
            }

            $this->repository->setUserCredits($targetId, $balance);
            $this->repository->addCreditAdjustment($targetId, $amount, $balance, $reason);
            $this->logs->record('warning', 'admin.credits_adjusted', [
                'reason' => $reason, 'amount' => $amount, 'balance' => $balance,
            ], $actorId, 'user', $targetId);

            return $balance;
        });
    }

    /** @param array<string,mixed> $input */
    public function updatePlan(int $actorId, int $planId, array $input): void
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Plan name is invalid.');
        }
        $price = $this->boundedInteger($input['price_cents'] ?? null, 0, 100000000, 'Plan price is invalid.');
        $minutes = $this->boundedInteger($input['monthly_minutes'] ?? null, 0, 10000000, 'Plan minutes are invalid.');
        $credits = $this->boundedInteger($input['credits'] ?? null, 0, self::MAX_BALANCE, 'Plan credits are invalid.');
        if (!is_bool($input['is_active'] ?? null)) {
            throw new InvalidArgumentException('Plan status is invalid.');
        }
        $features = $input['features'] ?? null;
        if (!is_array($features)) {
            throw new InvalidArgumentException('Plan features are invalid.');
        }
        $normalizedFeatures = PlanLimits::fromFeatures($features)->toArray();

        $this->transactional(function () use ($actorId, $planId, $name, $price, $minutes, $credits, $input, $normalizedFeatures): void {
            $this->assertActor($actorId);
            $plan = $this->repository->lockPlan($planId);
            if (!is_array($plan)) {
                throw new DomainException('Plan was not found.');
            }
            if (($plan['slug'] ?? null) === 'free' && $input['is_active'] === false) {
                throw new DomainException('The Free plan cannot be deactivated without a compatible replacement.');
            }

            $this->repository->updatePlan($planId, [
                'name' => $name,
                'price_cents' => $price,
                'monthly_minutes' => $minutes,
                'credits' => $credits,
                'features' => json_encode($normalizedFeatures, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'is_active' => $input['is_active'] ? 1 : 0,
            ]);
            $this->logs->record('warning', 'admin.plan_updated', ['status' => $input['is_active'] ? 'active' : 'inactive'], $actorId, 'plan', $planId);
        });
    }

    /** @param array<string,mixed> $input */
    public function createPlan(int $actorId, array $input): int
    {
        $slug=is_string($input['slug']??null)?trim($input['slug']):''; if(preg_match('/\A[a-z][a-z0-9-]{1,62}\z/D',$slug)!==1) throw new InvalidArgumentException('Plan slug is invalid.');
        $name=is_string($input['name']??null)?trim($input['name']):''; if($name===''||mb_strlen($name)>100) throw new InvalidArgumentException('Plan name is invalid.');
        $price=$this->boundedInteger($input['price_cents']??null,0,100000000,'Plan price is invalid.'); $minutes=$this->boundedInteger($input['monthly_minutes']??null,0,10000000,'Plan minutes are invalid.'); $credits=$this->boundedInteger($input['credits']??null,0,self::MAX_BALANCE,'Plan credits are invalid.');
        $features=PlanLimits::fromFeatures((array)($input['features']??[]))->toArray(); $active=(bool)($input['is_active']??false);
        return $this->transactional(function()use($actorId,$slug,$name,$price,$minutes,$credits,$features,$active):int{$this->assertActor($actorId);$id=$this->repository->createPlan(['slug'=>$slug,'name'=>$name,'price_cents'=>$price,'monthly_minutes'=>$minutes,'credits'=>$credits,'features'=>json_encode($features,JSON_THROW_ON_ERROR),'is_active'=>$active?1:0]);$this->logs->record('warning','admin.plan_updated',['status'=>$active?'active':'inactive'],$actorId,'plan',$id);return $id;});
    }

    /** @return array<string,mixed> */
    private function userName(mixed $value): string { $value=is_string($value)?trim($value):''; if($value===''||mb_strlen($value)>120) throw new InvalidArgumentException('Name is invalid.'); return $value; }
    private function email(mixed $value): string { $value=is_string($value)?mb_strtolower(trim($value)):''; if($value===''||mb_strlen($value)>254||filter_var($value,FILTER_VALIDATE_EMAIL)===false) throw new InvalidArgumentException('Email is invalid.'); return $value; }
    private function role(mixed $value): string { if(!is_string($value)||!in_array($value,['user','admin'],true)) throw new InvalidArgumentException('Role is invalid.'); return $value; }

    private function assertActor(int $actorId): array
    {
        $actor = $this->repository->lockUser($actorId);
        if (!is_array($actor) || ($actor['role'] ?? null) !== 'admin' || ($actor['status'] ?? null) !== 'active') {
            throw new DomainException('An active administrator is required.');
        }

        return $actor;
    }

    /** @return array<string,mixed> */
    private function requiredUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User identifier is invalid.');
        }
        $user = $this->repository->lockUser($userId);
        if (!is_array($user)) {
            throw new DomainException('User was not found.');
        }

        return $user;
    }

    private function reason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $reason) ?? '');
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('An administrative reason is required.');
        }

        return $reason;
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum, string $message): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint = 'admin_action';
        if ($ownsTransaction) {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Administrative transaction could not start.');
            }
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                if (!$this->pdo->commit()) {
                    throw new RuntimeException('Administrative transaction could not commit.');
                }
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }
}
