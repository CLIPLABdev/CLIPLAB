<?php
declare(strict_types=1);
namespace App\Communications;
use App\Contracts\CommunicationEmitter;
use PDO;

final class EmailVerificationService
{
    public function __construct(private PDO $pdo,private CommunicationEmitter $communications,private string $baseUrl) {}
    public function request(int $userId):void {
        $this->transaction(function()use($userId):void {
            $s=$this->pdo->prepare('SELECT id,name,email,status,email_verified_at FROM users WHERE id=:id'.$this->lockClause());$s->execute(['id'=>$userId]);$user=$s->fetch(PDO::FETCH_ASSOC);
            if(!is_array($user)||$user['status']!=='active')throw new \InvalidArgumentException('Conta indisponível para confirmação.');
            if($user['email_verified_at']!==null)return;
            $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
            $s=$this->pdo->prepare('UPDATE email_verification_challenges SET used_at=CURRENT_TIMESTAMP WHERE user_id=:id AND used_at IS NULL');$s->execute(['id'=>$userId]);
            $s=$this->pdo->prepare('INSERT INTO email_verification_challenges(user_id,email,token_hash,expires_at) VALUES(:user,:email,:hash,:expires)');$s->execute(['user'=>$userId,'email'=>$user['email'],'hash'=>$hash,'expires'=>gmdate('Y-m-d H:i:s',time()+3600)]);
            $this->communications->cancelByDedupePrefix($userId,'email-verification:'.$userId.':');
            $url=rtrim($this->baseUrl,'/').'/verificar-email?token='.$token;(new EmailHtmlPolicy())->validateUrl($url);
            $this->communications->emit($userId,'auth.email_verification',['nome_usuario'=>(string)$user['name'],'link_confirmacao'=>$url],'email-verification:'.$userId.':'.$hash,(string)$user['email'],['email']);
        });
    }
    public function confirm(string $token):bool {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))return false;
        return $this->transaction(function()use($token):bool {
            $hash=hash('sha256',$token);
            $s=$this->pdo->prepare('SELECT user_id FROM email_verification_challenges WHERE token_hash=:hash');$s->execute(['hash'=>$hash]);$userId=$s->fetchColumn();if($userId===false)return false;
            // Same lock order as request and profile changes: account, then challenge.
            $s=$this->pdo->prepare('SELECT email,status FROM users WHERE id=:id'.$this->lockClause());$s->execute(['id'=>$userId]);$user=$s->fetch(PDO::FETCH_ASSOC);
            $s=$this->pdo->prepare('SELECT id,email FROM email_verification_challenges WHERE token_hash=:hash AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP'.$this->lockClause());$s->execute(['hash'=>$hash]);$challenge=$s->fetch(PDO::FETCH_ASSOC);
            if(!is_array($user)||!is_array($challenge)||$user['status']!=='active'||!hash_equals((string)$challenge['email'],(string)$user['email']))return false;
            $s=$this->pdo->prepare('UPDATE email_verification_challenges SET used_at=CURRENT_TIMESTAMP WHERE id=:id AND used_at IS NULL');$s->execute(['id'=>$challenge['id']]);if($s->rowCount()!==1)return false;
            $s=$this->pdo->prepare('UPDATE users SET email_verified_at=CURRENT_TIMESTAMP WHERE id=:id');$s->execute(['id'=>$userId]);
            $this->communications->cancelByDedupePrefix((int)$userId,'email-verification:'.$userId.':');return true;
        });
    }
    private function lockClause():string{return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private function transaction(callable $operation):mixed {$owns=!$this->pdo->inTransaction();if($owns)$this->pdo->beginTransaction();try{$result=$operation();if($owns)$this->pdo->commit();return $result;}catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
