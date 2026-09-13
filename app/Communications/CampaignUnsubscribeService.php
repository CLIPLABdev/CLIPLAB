<?php
declare(strict_types=1);
namespace App\Communications;
use App\Security\SecretCipher; use DateTimeImmutable; use DateTimeZone; use InvalidArgumentException; use PDO;
final class CampaignUnsubscribeService {
    private const AAD='clipforge:campaign-unsubscribe:v1'; private \Closure $clock;
    public function __construct(private PDO $pdo,private SecretCipher $cipher,private string $baseUrl,?callable $clock=null){$this->clock=$clock===null?static fn():DateTimeImmutable=>new DateTimeImmutable('now',new DateTimeZone('UTC')):\Closure::fromCallable($clock);}
    public function tokenFor(int $userId):string {if($userId<1)throw new InvalidArgumentException('Token inválido.');$now=($this->clock)();return $this->cipher->encrypt(json_encode(['user_id'=>$userId,'expires_at'=>$now->modify('+365 days')->getTimestamp()],JSON_THROW_ON_ERROR),self::AAD);}
    public function urlFor(int $userId):string {return rtrim($this->validBase(),'/').'/cancelar-inscricao?token='.rawurlencode($this->tokenFor($userId));}
    public function resolve(string $token):int {try{$data=json_decode($this->cipher->decrypt($token,self::AAD),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new InvalidArgumentException('Link inválido.');}if(!is_array($data)||(int)($data['user_id']??0)<1||(int)($data['expires_at']??0)<($this->clock)()->getTimestamp())throw new InvalidArgumentException('Link inválido ou expirado.');return (int)$data['user_id'];}
    public function unsubscribe(string $token):void {$user=$this->resolve($token);$now=($this->clock)()->format('Y-m-d H:i:s');$mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$sql="INSERT INTO communication_preferences(user_id,category,email_enabled,marketing_opted_in_at) VALUES(:user,'marketing',0,NULL)".($mysql?' ON DUPLICATE KEY UPDATE email_enabled=0,marketing_opted_in_at=NULL':' ON CONFLICT(user_id,category) DO UPDATE SET email_enabled=0,marketing_opted_in_at=NULL');$this->pdo->prepare($sql)->execute(['user'=>$user]);$this->pdo->prepare("UPDATE communication_email_outbox SET status='cancelled',payload_ciphertext=NULL WHERE user_id=? AND event='marketing.campaign' AND status IN ('pending','retry')")->execute([$user]);}
    private function validBase():string {$parts=parse_url($this->baseUrl);if(!is_array($parts)||!in_array($parts['scheme']??'', ['https','http'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['query'])||isset($parts['fragment']))throw new InvalidArgumentException('URL indisponível.');return $this->baseUrl;}
}
