<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Mailer;
use App\Contracts\CommunicationEmitter;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

final class PasswordResetService
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $tokens,
        private ?Mailer $mailer,
        private string $baseUrl,
        private ?CommunicationEmitter $communications = null
    ) {
        if ($mailer === null && $communications === null) throw new \InvalidArgumentException('A durable emitter or mail transport is required.');
    }

    public function request(string $email): void
    {
        $user = $this->users->findByEmail(mb_strtolower(trim($email)));

        if ($user === null) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $url = rtrim($this->baseUrl, '/') . '/redefinir-senha?token=' . rawurlencode($token);
        $enqueue = $this->communications === null ? null : function () use ($user, $url, $token): void {
            $this->communications->cancelByDedupePrefix((int) $user['id'], 'password-reset:' . (int) $user['id'] . ':');
            $this->communications->emit((int) $user['id'], 'auth.password_reset', ['nome_usuario' => (string) ($user['name'] ?? 'Usuário'), 'link_recuperacao' => $url], 'password-reset:' . (int) $user['id'] . ':' . hash('sha256', $token), (string) $user['email'], ['email']);
        };
        $created = $this->tokens->replaceForUser(
            (int) $user['id'],
            hash('sha256', $token),
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 minutes')->format('Y-m-d H:i:s'),
            $enqueue,
            (string) $user['email']
        );

        // Preserve the generic forgot-password response: a stale identity issues nothing.
        if (!$created) return;

        $url = rtrim($this->baseUrl, '/') . '/redefinir-senha?token=' . rawurlencode($token);
        if ($this->communications !== null) {
            return;
        }
        $this->mailer->send((string) $user['email'], 'Redefina sua senha', '<a href="' . e($url) . '">Redefinir senha</a>');
    }

    public function reset(string $token, string $password): bool
    {
        if ($token === '' || $password === '') {
            return false;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        return $this->tokens->consumeValidToken(hash('sha256', $token), function (int $userId) use ($passwordHash): void {
            $this->users->updatePassword($userId, $passwordHash);
        });
    }
}
