<?php
declare(strict_types=1);
namespace App\Communications;
use App\Security\SecretCipher;
use App\Services\MailerFactory;
use PDO;
use InvalidArgumentException;
final class CommunicationMailSettingsService
{
    private const AAD='clipforge:communications:smtp:v1';
    public function __construct(private PDO $pdo,private SecretCipher $cipher,private array $fallback) {}
    private function row():?array {$row=$this->pdo->query('SELECT * FROM communication_mail_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}
    public function save(int $actor,array $input):void {
        $host=strtolower(trim((string)($input['smtp_host']??'')));$from=mb_strtolower(trim((string)($input['from_address']??'')));$port=(int)($input['smtp_port']??0);$enc=strtolower(trim((string)($input['smtp_encryption']??'')));$user=trim((string)($input['smtp_username']??''));$password=(string)($input['smtp_password']??'');$name=trim((string)($input['from_name']??'ClipLab'));
        if($actor<1 || !preg_match('/^[a-z0-9][a-z0-9.-]{0,253}$/D',$host) || preg_match('/[\r\n\x00]/',$name.$user) || strlen($name)>120 || strlen($user)>254 || strlen($password)>4096)throw new InvalidArgumentException('Configuração SMTP inválida.');
        $previous=$this->row();$secret=null;
        if($password!=='' && $user==='')throw new InvalidArgumentException('Informe o usuário SMTP.');
        if($user!=='' && $password==='') {
            if($previous===null || $previous['smtp_host']!==$host || (int)$previous['smtp_port']!==$port || $previous['smtp_encryption']!==$enc || $previous['smtp_username']!==$user || empty($previous['smtp_password_ciphertext']))throw new InvalidArgumentException('Informe a senha ao alterar o servidor ou usuário SMTP.');
            $secret=$previous['smtp_password_ciphertext'];$password=$this->cipher->decrypt($secret,self::AAD);
        }elseif($password!=='')$secret=$this->cipher->encrypt($password,self::AAD);
        $timeout=max(1,min(60,(int)($input['smtp_timeout']??10)));
        try{MailerFactory::make(['transport'=>'smtp','smtp_host'=>$host,'smtp_port'=>$port,'smtp_encryption'=>$enc,'smtp_username'=>$user,'smtp_password'=>$password,'from_address'=>$from,'from_name'=>$name,'smtp_timeout'=>$timeout]);}catch(\Throwable){throw new InvalidArgumentException('Use SMTP com TLS/SSL e credenciais válidas.');}
        $columns=['from_address'=>'from','from_name'=>'name','smtp_host'=>'host','smtp_port'=>'port','smtp_encryption'=>'enc','smtp_username'=>'user','smtp_password_ciphertext'=>'secret','smtp_timeout'=>'timeout','updated_by'=>'actor'];
        $sql='INSERT INTO communication_mail_settings(id,'.implode(',',array_keys($columns)).") VALUES(1,:".implode(',:',array_values($columns)).')';
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$sql.=$mysql?' ON DUPLICATE KEY UPDATE ':' ON CONFLICT(id) DO UPDATE SET ';
        $updates=[];foreach($columns as $column=>$parameter)$updates[]=$column.'='.($mysql?'VALUES('.$column.')':'excluded.'.$column);
        $sql.=implode(',',$updates).",last_test_status='untested',last_test_code=NULL,last_tested_at=NULL";
        $s=$this->pdo->prepare($sql);$s->execute(compact('from','name','host','port','enc','user','secret','timeout','actor'));
    }
    public function publicState():array {
        $row=$this->row();$source=$row===null?'environment':'database';$data=$row??$this->fallback;
        $state=array_intersect_key($data,array_flip(['from_address','from_name','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_timeout','last_test_status','last_test_code','last_tested_at']));
        $state['source']=$source;$state['has_password']=$row===null?!empty($data['smtp_password']):!empty($data['smtp_password_ciphertext']);
        try{$config=$this->effective();$state['configured']=($config['transport']??'')==='smtp';if($state['configured'])MailerFactory::make($config);}catch(\Throwable){$state['configured']=false;}
        return $state;
    }
    public function effective():array {
        $r=$this->row();if($r===null)return $this->fallback;
        return ['environment'=>'production','transport'=>'smtp','from_address'=>$r['from_address'],'from_name'=>$r['from_name'],'smtp_host'=>$r['smtp_host'],'smtp_port'=>(int)$r['smtp_port'],'smtp_encryption'=>$r['smtp_encryption'],'smtp_username'=>$r['smtp_username']??'','smtp_password'=>empty($r['smtp_password_ciphertext'])?'':$this->cipher->decrypt($r['smtp_password_ciphertext'],self::AAD),'smtp_timeout'=>(int)$r['smtp_timeout']];
    }
    public function recordTest(bool $success):void {$s=$this->pdo->prepare('UPDATE communication_mail_settings SET last_test_status=:status,last_test_code=:code,last_tested_at=CURRENT_TIMESTAMP WHERE id=1');$s->execute(['status'=>$success?'success':'failed','code'=>$success?'smtp_accepted':'smtp_unavailable']);}
}
