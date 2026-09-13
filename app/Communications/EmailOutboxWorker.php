<?php
declare(strict_types=1);
namespace App\Communications;
use App\Contracts\Mailer;
use App\Security\SecretCipher;
use App\Services\LogMailer;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class EmailOutboxWorker
{
    public function __construct(private PDO $pdo,private SecretCipher $cipher,private Mailer $mailer,private ?EmailTemplateRenderer $renderer=null,private ?CommunicationEventCatalog $catalog=null,private int $maxAttempts=3,private int $leaseSeconds=120) {
        $this->renderer??=new EmailTemplateRenderer();$this->catalog??=new CommunicationEventCatalog();
        if($maxAttempts<1||$maxAttempts>10||$leaseSeconds<30||$mailer instanceof LogMailer)throw new RuntimeException('Worker requires a delivery transport and valid retry limits.');
    }
    /** sent means accepted by transport, never confirmed inbox delivery. */
    public function processOne(?DateTimeImmutable $now=null):string {
        $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $row=$this->claim($now);if($row===null)return 'idle';
        if($row['exhausted'])return $this->finish($row,'failed','lease_expired',$now);
        try {
            $payload=json_decode($this->cipher->decrypt((string)$row['payload_ciphertext'],'clipforge:communications:v1'),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($payload)||($payload['event']??null)!==$row['event']||!is_array($payload['variables']??null))throw new RuntimeException('Invalid payload.');
            $definition=$this->catalog->definition($row['event'],$payload['variables']);if($definition['category']!==$row['category'])throw new RuntimeException('Invalid category.');
            if(!(new CommunicationInboxService($this->pdo))->emailEnabled((int)$row['user_id'],$row['category']))return $this->finish($row,'cancelled','opt_out',$now);
            if($row['event']==='marketing.campaign'&&!$this->campaignActive((string)$row['dedupe_key']))return $this->finish($row,'cancelled','campaign_cancelled',$now);
            $template=null;
            if($row['event']==='marketing.campaign'&&is_array($payload['template_snapshot']??null)){
                $snapshot=$payload['template_snapshot'];
                if(!is_string($snapshot['subject_template']??null)||!is_string($snapshot['html_template']??null)||!is_string($snapshot['text_template']??null)||mb_strlen($snapshot['subject_template'])>255||strlen($snapshot['html_template'])>200000)throw new RuntimeException('Invalid campaign snapshot.');
                $template=['subject_template'=>$snapshot['subject_template'],'html_template'=>$snapshot['html_template']];
            }
            if(!is_array($template)){$s=$this->pdo->prepare("SELECT subject_template,html_template FROM communication_email_templates WHERE event=:event AND locale='pt-BR' AND is_active=1 ORDER BY version DESC LIMIT 1");$s->execute(['event'=>$row['event']]);$template=$s->fetch(PDO::FETCH_ASSOC);}
            if(!is_array($template))throw new RuntimeException('Active template unavailable.');
            $allowed=array_keys($definition['variables']);$subject=$this->renderer->renderSubject($template['subject_template'],$payload['variables'],$allowed);$html=$this->renderer->render($template['html_template'],$payload['variables'],$allowed);
            if($row['event']==='marketing.campaign'){$url=$payload['unsubscribe_url']??null;$parts=is_string($url)?parse_url($url):false;if(!is_array($parts)||!in_array($parts['scheme']??'', ['https','http'],true)||empty($parts['host'])||isset($parts['user']))throw new RuntimeException('Invalid unsubscribe URL.');$html.='<p><a href="'.htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">Cancelar inscrição</a></p>';}
            $html=(new EmailDocument())->render($subject,$html);
            if(!$this->recipientDeliverable($row,$now))return $this->finish($row,'cancelled','recipient_ineligible',$now);
            if($row['event']==='marketing.campaign'&&!$this->campaignActive((string)$row['dedupe_key']))return $this->finish($row,'cancelled','campaign_cancelled',$now);
            if(!(new CommunicationInboxService($this->pdo))->emailEnabled((int)$row['user_id'],$row['category']))return $this->finish($row,'cancelled','opt_out',$now);
            $this->mailer->send($row['recipient'],$subject,$html);
        } catch(Throwable) {
            return $this->finish($row,(int)$row['attempts']>=$this->maxAttempts?'failed':'retry','transport_or_template_failed',$now);
        }
        // Do not reclassify persistence errors after SMTP acceptance as a delivery failure.
        return $this->finish($row,'sent',null,$now);
    }
    private function claim(DateTimeImmutable $now):?array {
        $this->pdo->beginTransaction();
        try {
            $sql="SELECT * FROM communication_email_outbox WHERE ((status IN ('pending','retry') AND available_at<=:ready) OR (status='leased' AND leased_until<:expired)) ORDER BY CASE WHEN category='marketing' THEN 1 ELSE 0 END,id LIMIT 1";
            if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql.=' FOR UPDATE';
            $s=$this->pdo->prepare($sql);$s->execute(['ready'=>$now->format('Y-m-d H:i:s'),'expired'=>$now->format('Y-m-d H:i:s')]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)){$this->pdo->commit();return null;}
            $exhausted=(int)$row['attempts']>=$this->maxAttempts;
            if($row['status']==='leased'&&!$exhausted)$this->audit((int)$row['id'],(int)$row['attempts'],'retry','lease_expired');
            $hash=hash('sha256',random_bytes(32));$attempts=$exhausted?(int)$row['attempts']:(int)$row['attempts']+1;
            $s=$this->pdo->prepare("UPDATE communication_email_outbox SET status='leased',attempts=:attempts,lease_token_hash=:hash,leased_until=:lease WHERE id=:id AND (status IN ('pending','retry') OR (status='leased' AND leased_until<:expired))");
            $s->execute(['attempts'=>$attempts,'hash'=>$hash,'lease'=>$now->modify('+'.$this->leaseSeconds.' seconds')->format('Y-m-d H:i:s'),'id'=>$row['id'],'expired'=>$now->format('Y-m-d H:i:s')]);
            if($s->rowCount()!==1)throw new RuntimeException('Lease unavailable.');
            $this->pdo->commit();$row['lease_token_hash']=$hash;$row['attempts']=$attempts;$row['exhausted']=$exhausted;return $row;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function finish(array $row,string $status,?string $code,DateTimeImmutable $now):string {
        $terminal=$status!=='retry';$this->pdo->beginTransaction();
        try {
            $sql="UPDATE communication_email_outbox SET status=:status,last_error_code=:code,leased_until=NULL,lease_token_hash=NULL,available_at=:available,sent_at=:sent".($terminal?',payload_ciphertext=NULL':'')." WHERE id=:id AND status='leased' AND lease_token_hash=:hash";
            $s=$this->pdo->prepare($sql);$s->execute(['status'=>$status,'code'=>$code,'available'=>$now->modify('+'.($terminal?0:min(900,30*(2**max(0,(int)$row['attempts']-1)))).' seconds')->format('Y-m-d H:i:s'),'sent'=>$status==='sent'?$now->format('Y-m-d H:i:s'):null,'id'=>$row['id'],'hash'=>$row['lease_token_hash']]);
            if($s->rowCount()!==1)throw new RuntimeException('Lease was lost.');
            $this->audit((int)$row['id'],(int)$row['attempts'],$status==='sent'?'accepted':($status==='cancelled'?'failed':$status),$code);
            $this->pdo->commit();return $status;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function audit(int $id,int $attempt,string $outcome,?string $code):void {
        $s=$this->pdo->prepare('INSERT INTO communication_email_attempts(outbox_id,attempt_number,outcome,error_code) VALUES(:id,:attempt,:outcome,:code)');$s->execute(compact('id','attempt','outcome','code'));
    }
    private function campaignActive(string $key):bool {if(preg_match('/\Acampaign:([1-9][0-9]*):user:[1-9][0-9]*\z/D',$key,$m)!==1)return false;$s=$this->pdo->prepare("SELECT status FROM communication_campaigns WHERE id=?");$s->execute([(int)$m[1]]);return in_array($s->fetchColumn(),['scheduled','processing','completed'],true);}
    private function recipientDeliverable(array $row,DateTimeImmutable $now):bool {
        return match($row['event']) {
            'account.email_change_requested'=>$this->emailChangeRequestIsCurrent($row,$now),
            'account.email_changed'=>$this->emailChangedNoticeIsValid($row),
            'auth.email_verification'=>$this->verificationIsCurrent($row,$now),
            'auth.password_reset'=>$this->passwordResetIsCurrent($row,$now),
            default=>$this->activeCurrentRecipient($row),
        };
    }
    private function activeCurrentRecipient(array $row):bool {$s=$this->pdo->prepare("SELECT 1 FROM users WHERE id=? AND status='active' AND email=?");$s->execute([(int)$row['user_id'],(string)$row['recipient']]);return $s->fetchColumn()!==false;}
    private function verificationIsCurrent(array $row,DateTimeImmutable $now):bool {if(preg_match('/\Aemail-verification:([1-9][0-9]*):([a-f0-9]{64})\z/D',(string)$row['dedupe_key'],$m)!==1||(int)$m[1]!==((int)$row['user_id']))return false;$s=$this->pdo->prepare("SELECT 1 FROM email_verification_challenges c INNER JOIN users u ON u.id=c.user_id WHERE c.user_id=? AND c.token_hash=? AND c.email=? AND c.used_at IS NULL AND c.expires_at>? AND u.status='active' AND u.email=?");$s->execute([(int)$row['user_id'],$m[2],(string)$row['recipient'],$now->format('Y-m-d H:i:s'),(string)$row['recipient']]);return $s->fetchColumn()!==false;}
    private function passwordResetIsCurrent(array $row,DateTimeImmutable $now):bool {if(preg_match('/\Apassword-reset:([1-9][0-9]*):([a-f0-9]{64})\z/D',(string)$row['dedupe_key'],$m)!==1||(int)$m[1]!==((int)$row['user_id']))return false;$s=$this->pdo->prepare("SELECT 1 FROM password_reset_tokens p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=? AND p.token_hash=? AND p.used_at IS NULL AND p.expires_at>? AND u.status='active' AND u.email=?");$s->execute([(int)$row['user_id'],$m[2],$now->format('Y-m-d H:i:s'),(string)$row['recipient']]);return $s->fetchColumn()!==false;}
    private function emailChangeRequestIsCurrent(array $row,DateTimeImmutable $now):bool {if(preg_match('/\Aemail-change:([1-9][0-9]*):([a-f0-9]{64})\z/D',(string)$row['dedupe_key'],$m)!==1||(int)$m[1]!==((int)$row['user_id']))return false;$s=$this->pdo->prepare("SELECT 1 FROM account_email_changes c INNER JOIN users u ON u.id=c.user_id WHERE c.user_id=? AND c.token_hash=? AND c.requested_email=? AND c.consumed_at IS NULL AND c.revoked_at IS NULL AND c.expires_at>? AND u.status='active' AND u.email=c.current_email");$s->execute([(int)$row['user_id'],$m[2],(string)$row['recipient'],$now->format('Y-m-d H:i:s')]);return $s->fetchColumn()!==false;}
    private function emailChangedNoticeIsValid(array $row):bool {if(preg_match('/\Aemail-confirmed:([1-9][0-9]*)\z/D',(string)$row['dedupe_key'],$m)!==1)return false;$s=$this->pdo->prepare("SELECT 1 FROM account_email_changes WHERE id=? AND user_id=? AND current_email=? AND consumed_at IS NOT NULL");$s->execute([(int)$m[1],(int)$row['user_id'],(string)$row['recipient']]);return $s->fetchColumn()!==false;}
}
