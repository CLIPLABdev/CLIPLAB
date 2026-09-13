<?php
declare(strict_types=1);
namespace App\Billing;

use PDO;
use Throwable;
use DomainException;

final class BillingRepository
{
    public function __construct(private PDO $pdo) {}
    public function transaction(callable $operation)
    {
        if($this->pdo->inTransaction()) throw new \LogicException('Billing requires its own transaction boundary.');
        $this->pdo->beginTransaction();
        try {$result=$operation();$this->pdo->commit();return $result;} catch(Throwable $e) {if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function one(string $sql,array $parameters=[]): ?array
    {
        $statement=$this->pdo->prepare($sql);$statement->execute($parameters);return $statement->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function all(string $sql,array $parameters=[]): array
    {
        $statement=$this->pdo->prepare($sql);$statement->execute($parameters);return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function execute(string $sql,array $parameters=[]): int
    {
        $statement=$this->pdo->prepare($sql);$statement->execute($parameters);return $statement->rowCount();
    }
    public function insertId(): int { return (int)$this->pdo->lastInsertId(); }
    public function lockSuffix(): string {return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    public function requireTransaction(): void {if(!$this->pdo->inTransaction())throw new \LogicException('A billing transaction is required.');}
    public function lockUser(int $id): array
    {
        $row=$this->one('SELECT id,plan_id,email FROM users WHERE id=?'.($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id]);
        if(!$row)throw new DomainException('Conta indisponível.');return $row;
    }
    public function attempt(string $id): ?array {return $this->one('SELECT * FROM billing_checkout_attempts WHERE id=?',[$id]);}
    public function attemptForUser(string $id,int $user): ?array {return $this->one('SELECT * FROM billing_checkout_attempts WHERE id=? AND user_id=?',[$id,$user]);}
    public function activePlans(): array
    {
        return $this->pdo->query('SELECT id,name,price_cents FROM plans WHERE is_active=1 AND price_cents>0 ORDER BY price_cents,id')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function history(int $user,int $page=1): array
    {
        $s=$this->pdo->prepare('SELECT * FROM billing_payments WHERE user_id=? ORDER BY id DESC LIMIT 25 OFFSET '.(max(0,min(10000,$page-1))*25));$s->execute([$user]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function subscriptions(int $user): array
    {
        $s=$this->pdo->prepare('SELECT * FROM billing_subscriptions WHERE user_id=? ORDER BY id DESC LIMIT 25');$s->execute([$user]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
