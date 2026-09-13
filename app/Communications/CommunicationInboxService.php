<?php
declare(strict_types=1);
namespace App\Communications;
use PDO;
use InvalidArgumentException;
final class CommunicationInboxService
{
    public function __construct(private PDO $pdo) {}
    public function setMarketingOptIn(int $userId,bool $optIn):void {
        if($userId<1)throw new InvalidArgumentException('Usuário inválido.');
        $sql="INSERT INTO communication_preferences(user_id,category,email_enabled,in_app_enabled,marketing_opted_in_at,updated_at) VALUES(:user,'marketing',:enabled,1,:opted,CURRENT_TIMESTAMP)";
        $sql.=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE email_enabled=VALUES(email_enabled),marketing_opted_in_at=VALUES(marketing_opted_in_at),updated_at=CURRENT_TIMESTAMP':' ON CONFLICT(user_id,category) DO UPDATE SET email_enabled=excluded.email_enabled,marketing_opted_in_at=excluded.marketing_opted_in_at,updated_at=CURRENT_TIMESTAMP';
        $s=$this->pdo->prepare($sql);$s->execute(['user'=>$userId,'enabled'=>$optIn?1:0,'opted'=>$optIn?gmdate('Y-m-d H:i:s'):null]);
    }
    public function marketingOptedIn(int $userId):bool {$s=$this->pdo->prepare("SELECT email_enabled,marketing_opted_in_at FROM communication_preferences WHERE user_id=:user AND category='marketing'");$s->execute(['user'=>$userId]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)&&(int)$r['email_enabled']===1&&$r['marketing_opted_in_at']!==null;}
    public function emailEnabled(int $userId,string $category):bool {
        if($category==='marketing')return $this->marketingOptedIn($userId);
        return $this->optionalChannelEnabled($userId,$category,'email_enabled');
    }
    public function inAppEnabled(int $userId,string $category):bool {
        if($category==='marketing')return false;
        return $this->optionalChannelEnabled($userId,$category,'in_app_enabled');
    }
    private function optionalChannelEnabled(int $userId,string $category,string $column):bool {
        // Security/account and billing remain mandatory, even with forged DB preferences.
        if(!in_array($category,['processing','usage'],true))return true;
        $s=$this->pdo->prepare('SELECT '.$column.' FROM communication_preferences WHERE user_id=:user AND category=:category');
        $s->execute(['user'=>$userId,'category'=>$category]);$value=$s->fetchColumn();
        return $value===false||(int)$value===1;
    }
    public function preferences(int $userId):array {
        return ['processing_email'=>$this->emailEnabled($userId,'processing'),'processing_in_app'=>$this->inAppEnabled($userId,'processing'),
            'usage_email'=>$this->emailEnabled($userId,'usage'),'usage_in_app'=>$this->inAppEnabled($userId,'usage'),'marketing_opt_in'=>$this->marketingOptedIn($userId)];
    }
    /** Only allowlisted checkbox fields are persisted; no client-supplied owner/category. */
    public function savePreferences(int $userId,array $input):void {
        if($userId<1)throw new InvalidArgumentException('Usuário inválido.');
        $owns=!$this->pdo->inTransaction();if($owns)$this->pdo->beginTransaction();
        try {
            foreach(['processing','usage'] as $category){
                $sql='INSERT INTO communication_preferences(user_id,category,email_enabled,in_app_enabled,updated_at) VALUES(:user,:category,:email,:in_app,CURRENT_TIMESTAMP)';
                $sql.=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE email_enabled=VALUES(email_enabled),in_app_enabled=VALUES(in_app_enabled),updated_at=CURRENT_TIMESTAMP':' ON CONFLICT(user_id,category) DO UPDATE SET email_enabled=excluded.email_enabled,in_app_enabled=excluded.in_app_enabled,updated_at=CURRENT_TIMESTAMP';
                $s=$this->pdo->prepare($sql);$s->execute(['user'=>$userId,'category'=>$category,'email'=>($input[$category.'_email']??null)==='1'?1:0,'in_app'=>($input[$category.'_in_app']??null)==='1'?1:0]);
            }
            $this->setMarketingOptIn($userId,($input['marketing_opt_in']??null)==='1');
            if($owns)$this->pdo->commit();
        }catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function notifications(int $userId):array {$s=$this->pdo->prepare('SELECT id,event,category,title,body,read_at,created_at FROM communication_notifications WHERE user_id=:user ORDER BY created_at DESC,id DESC LIMIT 100');$s->execute(['user'=>$userId]);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function markRead(int $userId,int $id):void {$s=$this->pdo->prepare('UPDATE communication_notifications SET read_at=COALESCE(read_at,CURRENT_TIMESTAMP) WHERE id=:id AND user_id=:user');$s->execute(['id'=>$id,'user'=>$userId]);}
}
