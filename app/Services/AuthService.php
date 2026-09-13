<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use PDO;
use RuntimeException;
use Throwable;

final class AuthService
{
    public function __construct(
        private PDO $pdo,
        private UserRepository $users,
        private PlanRepository $plans,
        private ?\App\Contracts\CommunicationEmitter $communications = null,
        private ?\App\Communications\EmailVerificationService $verification = null
    ) {
    }

    /** @param array<string, mixed> $input */
    public function register(array $input): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $plan = $this->plans->findActiveBySlug('free');

        if ($plan === null) {
            throw new RuntimeException('The Free plan is not available.');
        }

        $this->pdo->beginTransaction();

        try {
            $credits = (int) $plan['credits'];
            $userId = $this->users->create(
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                (int) $plan['id'],
                $credits
            );
            $this->users->recordInitialCredit($userId, $credits);
            $this->communications?->emit($userId, 'auth.welcome', ['nome_usuario' => $name], 'welcome:' . $userId, $email);
            $this->verification?->request($userId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $this->login($userId);

        return $userId;
    }

    public function attempt(string $email, string $password): bool
    {
        $user = $this->users->findByEmail(mb_strtolower(trim($email)));

        if ($user === null || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $this->login((int) $user['id']);

        return true;
    }

    public function logout(): void
    {
        Session::start();
        $_SESSION = [];
        $this->regenerateSessionId();
        Csrf::rotate();
    }

    private function login(int $userId): void
    {
        Session::start();
        $this->regenerateSessionId();
        $_SESSION['user_id'] = $userId;
        Csrf::rotate();
    }

    private function regenerateSessionId(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
