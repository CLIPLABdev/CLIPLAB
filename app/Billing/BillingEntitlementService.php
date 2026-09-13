<?php
declare(strict_types=1);
namespace App\Billing;

/** The source pointer prevents an old subscription from revoking a later plan. */
final class BillingEntitlementService
{
    public function __construct(private BillingRepository $repository) {}
    public function grant(int $userId,int $subscriptionId,int $planId,int $endsAt): void
    {
        $this->repository->requireTransaction();if($endsAt<=time())return;
        $source=$this->repository->one('SELECT * FROM billing_entitlements WHERE user_id=?',[$userId]);
        $until=gmdate('Y-m-d H:i:s',$endsAt);
        if($source && (int)$source['subscription_id']===$subscriptionId && $source['valid_until']>$until)$until=$source['valid_until'];
        // Subscription insertion order is not checkout order: delayed callbacks may create
        // larger IDs. A different confirmed source must be explicitly ended first.
        if($source && (int)$source['subscription_id']!==$subscriptionId)return;
        if($source)$this->repository->execute('UPDATE billing_entitlements SET subscription_id=?,plan_id=?,valid_until=? WHERE user_id=?',[$subscriptionId,$planId,$until,$userId]);
        else $this->repository->execute('INSERT INTO billing_entitlements(user_id,subscription_id,plan_id,valid_until) VALUES(?,?,?,?)',[$userId,$subscriptionId,$planId,$until]);
        $this->repository->execute('UPDATE users SET plan_id=? WHERE id=?',[$planId,$userId]);
    }
    public function revoke(int $userId,int $subscriptionId): void
    {
        $this->repository->requireTransaction();$source=$this->repository->one('SELECT * FROM billing_entitlements WHERE user_id=? AND subscription_id=?',[$userId,$subscriptionId]);if(!$source)return;
        $free=$this->repository->one('SELECT id FROM plans WHERE price_cents=0 AND is_active=1 ORDER BY id LIMIT 1');
        if(!$free)throw new \DomainException('Plano gratuito indisponível para encerrar o acesso.');
        $this->repository->execute('UPDATE users SET plan_id=? WHERE id=? AND plan_id=?',[$free['id'],$userId,$source['plan_id']]);
        $this->repository->execute('DELETE FROM billing_entitlements WHERE user_id=? AND subscription_id=?',[$userId,$subscriptionId]);
    }
    /** Safe local expiry of previously confirmed periods; does not contact a provider. */
    public function expire(int $userId): void
    {
        $this->repository->transaction(function()use($userId){$this->repository->lockUser($userId);$source=$this->repository->one('SELECT * FROM billing_entitlements WHERE user_id=? AND valid_until<=?',[$userId,gmdate('Y-m-d H:i:s')]);if($source)$this->revoke($userId,(int)$source['subscription_id']);});
    }
}
