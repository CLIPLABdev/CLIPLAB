<?php
declare(strict_types=1);
namespace App\Account;

use App\Contracts\CommunicationEmitter;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/** Security changes and their durable notifications form one database transaction. */
final class ProfileAccountService
{
    private \Closure $clock;
    private string $baseUrl;

    public function __construct(private PDO $pdo, private CommunicationEmitter $events, string $baseUrl, ?callable $clock = null)
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user'], $parts['pass'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['scheme'] ?? '', ['http','https'], true)
            || (($parts['scheme'] ?? '') !== 'https' && !in_array(strtolower($parts['host']), ['localhost','127.0.0.1','[::1]'], true))) {
            throw new \InvalidArgumentException('A secure application URL is required.');
        }
        $this->baseUrl = rtrim($baseUrl,'/');
        $this->clock = $clock === null ? static fn () => new DateTimeImmutable('now',new DateTimeZone('UTC')) : \Closure::fromCallable($clock);
    }

    /** @return array{email_pending: bool} */
    public function updateDetails(int $userId, string $name, string $email, string $currentPassword): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/u',$name)) $errors['name'] = 'Informe um nome entre 2 e 120 caracteres.';
        if (strlen($email) > 254 || filter_var($email,FILTER_VALIDATE_EMAIL) === false) $errors['email'] = 'Informe um e-mail válido.';
        if ($errors !== []) throw new ProfileValidationException($errors);

        return $this->transaction(function () use ($userId,$name,$email,$currentPassword): array {
            $user = $this->activeUser($userId,true);
            $changingEmail = $email !== mb_strtolower($user['email']);
            if ($this->emailTaken($email,$userId)) throw new ProfileValidationException(['email'=>'Este e-mail já está em uso.']);
            if ($changingEmail) $this->verifyPassword($user,$currentPassword);
            $this->execute('UPDATE users SET name=:name WHERE id=:id',['name'=>$name,'id'=>$userId]);
            if (!$changingEmail) return ['email_pending'=>false];

            $now = $this->now();
            $this->revokeChallenges($userId,$now);
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256',$token);
            $this->execute('INSERT INTO account_email_changes (user_id,current_email,requested_email,token_hash,expires_at,created_at) VALUES (:user,:current,:requested,:hash,:expires,:created)',[
                'user'=>$userId,'current'=>$user['email'],'requested'=>$email,'hash'=>$hash,
                'expires'=>$this->timestamp(($this->clock)()->modify('+30 minutes')),'created'=>$now,
            ]);
            $this->events->emit($userId,'account.email_change_requested',[
                'nome_usuario'=>$name,'link_confirmacao'=>$this->baseUrl.'/perfil/confirmar-email?token='.rawurlencode($token),
            ],'email-change:'.$userId.':'.$hash,$email,['email']);
            return ['email_pending'=>true];
        });
    }

    public function confirmEmail(int $userId, string $token): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D',$token) !== 1) return false;
        try {
            return $this->transaction(function () use ($userId,$token): bool {
                $user = $this->activeUser($userId,true);
                $statement = $this->pdo->prepare('SELECT * FROM account_email_changes WHERE user_id=:user AND token_hash=:hash AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>:now LIMIT 1'.$this->lock());
                $statement->execute(['user'=>$userId,'hash'=>hash('sha256',$token),'now'=>$this->now()]);
                $challenge = $statement->fetch(PDO::FETCH_ASSOC);
                if ($challenge === false || strcasecmp($challenge['current_email'],$user['email']) !== 0 || $this->emailTaken($challenge['requested_email'],$userId)) return false;
                $this->execute('UPDATE users SET email=:email,email_verified_at=:verified WHERE id=:id',['email'=>$challenge['requested_email'],'verified'=>$this->now(),'id'=>$userId]);
                $this->execute('UPDATE account_email_changes SET consumed_at=:now WHERE id=:id',['now'=>$this->now(),'id'=>$challenge['id']]);
                $this->revokeChallenges($userId,$this->now());
                $this->execute('UPDATE password_reset_tokens SET used_at=:now WHERE user_id=:user AND used_at IS NULL',['now'=>$this->now(),'user'=>$userId]);
                $this->events->emit($userId,'account.email_changed',['nome_usuario'=>$user['name']], 'email-confirmed:'.$challenge['id'],$user['email']);
                return true;
            });
        } catch (ProfileValidationException $exception) {
            return false;
        } catch (PDOException $exception) {
            // A competing account may claim the email between validation and the unique update.
            if (in_array((string)$exception->getCode(),['23000','23505'],true)) return false;
            throw $exception;
        }
    }

    public function changePassword(int $userId, string $currentPassword, string $password, string $confirmation): void
    {
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password,"\0") || !hash_equals($password,$confirmation)) {
            throw new ProfileValidationException(['password'=>'Use de 12 a 72 bytes e confirme a nova senha corretamente.']);
        }
        $this->transaction(function () use ($userId,$currentPassword,$password): void {
            $user = $this->activeUser($userId,true);
            $this->verifyPassword($user,$currentPassword);
            if (password_verify($password,$user['password_hash'])) throw new ProfileValidationException(['password'=>'Escolha uma senha diferente da atual.']);
            $hash = password_hash($password,PASSWORD_DEFAULT);
            if (!is_string($hash)) throw new \RuntimeException('Password could not be secured.');
            $this->execute('UPDATE users SET password_hash=:hash WHERE id=:id',['hash'=>$hash,'id'=>$userId]);
            $this->execute('UPDATE password_reset_tokens SET used_at=:now WHERE user_id=:id AND used_at IS NULL',['now'=>$this->now(),'id'=>$userId]);
            $this->revokeChallenges($userId,$this->now());
            $this->events->emit($userId,'account.password_changed',['nome_usuario'=>$user['name']], 'password-changed:'.$userId.':'.bin2hex(random_bytes(16)),$user['email']);
        });
    }

    public function pendingEmail(int $userId): ?string
    {
        $statement = $this->pdo->prepare('SELECT requested_email FROM account_email_changes WHERE user_id=:user AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>:now ORDER BY id DESC LIMIT 1');
        $statement->execute(['user'=>$userId,'now'=>$this->now()]);
        $email = $statement->fetchColumn();
        return $email === false ? null : (string)$email;
    }

    public function cancelEmailChange(int $userId): void
    {
        $this->transaction(function () use ($userId): void {
            $this->activeUser($userId,true);
            $this->revokeChallenges($userId,$this->now());
        });
    }

    private function revokeChallenges(int $userId,string $now): void
    {
        $this->execute('UPDATE account_email_changes SET revoked_at=:now WHERE user_id=:user AND consumed_at IS NULL AND revoked_at IS NULL',['now'=>$now,'user'=>$userId]);
        $this->events->cancelByDedupePrefix($userId,'email-change:'.$userId.':');
    }

    private function activeUser(int $userId,bool $forUpdate=false): array
    {
        $statement = $this->pdo->prepare('SELECT id,name,email,password_hash,status FROM users WHERE id=:id LIMIT 1'.($forUpdate?$this->lock():''));
        $statement->execute(['id'=>$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if ($user === false || $user['status'] !== 'active') throw new ProfileValidationException(['form'=>'Sua conta não está disponível para alterações.']);
        return $user;
    }

    private function verifyPassword(array $user,string $password): void
    {
        if ($password === '' || strlen($password)>4096 || !password_verify($password,$user['password_hash'])) throw new ProfileValidationException(['current_password'=>'Informe sua senha atual corretamente para confirmar esta alteração.']);
    }

    private function emailTaken(string $email,int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE email=:email AND id<>:id LIMIT 1');
        $statement->execute(['email'=>$email,'id'=>$userId]);
        return $statement->fetchColumn() !== false;
    }

    private function execute(string $sql,array $params): void { $this->pdo->prepare($sql)->execute($params); }
    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'; }
    private function timestamp(DateTimeImmutable $time): string { return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    private function now(): string { return $this->timestamp(($this->clock)()); }

    private function transaction(callable $operation): mixed
    {
        $owns = !$this->pdo->inTransaction();
        $owns ? $this->pdo->beginTransaction() : $this->pdo->exec('SAVEPOINT profile_account_flow');
        try {
            $result = $operation();
            $owns ? $this->pdo->commit() : $this->pdo->exec('RELEASE SAVEPOINT profile_account_flow');
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $owns ? $this->pdo->rollBack() : $this->pdo->exec('ROLLBACK TO SAVEPOINT profile_account_flow');
            }
            throw $exception;
        }
    }
}
