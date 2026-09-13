<?php
declare(strict_types=1);
namespace App\Billing;

use App\Security\SecretCipher;
use PDO;
use DomainException;

final class GatewaySettingsService
{
    private const AAD = 'clipforge:billing:v1';
    public function __construct(private PDO $pdo, private SecretCipher $cipher) {}
    public static function validate(string $provider, string $environment): void
    {
        if (!in_array($provider,['stripe','pagarme'],true) || !in_array($environment,['sandbox','production'],true)) throw new DomainException('Gateway inválido.');
    }
    public function save(string $provider,string $environment,array $input,int $actor): void
    {
        self::validate($provider,$environment);
        $row=$this->row($provider,$environment);
        $secret=trim((string)($input['secret']??'')); $webhook=trim((string)($input['webhook_secret']??''));
        if ($secret !== '' && (strlen($secret)>512 || preg_match('/^sk_'.($environment==='sandbox'?'test':'live').'_[A-Za-z0-9_]{8,}$/D',$secret)!==1)) throw new DomainException('Chave incompatível com o ambiente.');
        if ($webhook !== '' && ($provider==='stripe' ? preg_match('/^whsec_[A-Za-z0-9_]{8,}$/D',$webhook)!==1 : preg_match('/^[A-Za-z0-9_-]{43,128}$/D',$webhook)!==1)) throw new DomainException('Segredo do webhook inválido.');
        $encrypted=$secret!==''?$this->cipher->encrypt($secret,self::AAD):($row['secret_ciphertext']??null);
        $hook=$webhook!==''?$this->cipher->encrypt($webhook,self::AAD):($row['webhook_secret_ciphertext']??null);
        $active= !empty($input['active']);
        if($active && (!$encrypted || !$hook)) throw new DomainException('Configure as duas credenciais antes de ativar.');
        $public=trim((string)($input['public_key']??($row['public_key']??'')));
        if(strlen($public)>512) throw new DomainException('Chave pública inválida.');
        $values=[$active?1:0,$public,$encrypted,$hook,$encrypted&&$hook?'configured':'missing',$actor,$provider,$environment];
        if($row) $sql='UPDATE billing_gateway_settings SET is_active=?,public_key=?,secret_ciphertext=?,webhook_secret_ciphertext=?,configuration_status=?,updated_by=? WHERE provider=? AND environment=?';
        else $sql='INSERT INTO billing_gateway_settings(is_active,public_key,secret_ciphertext,webhook_secret_ciphertext,configuration_status,updated_by,provider,environment) VALUES(?,?,?,?,?,?,?,?)';
        $this->pdo->prepare($sql)->execute($values);
    }
    public function masked(): array
    {
        $rows=$this->pdo->query('SELECT provider,environment,is_active,public_key,configuration_status,secret_ciphertext,webhook_secret_ciphertext FROM billing_gateway_settings ORDER BY provider,environment')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) { $row['has_secret']=!empty($row['secret_ciphertext']); $row['has_webhook_secret']=!empty($row['webhook_secret_ciphertext']); unset($row['secret_ciphertext'],$row['webhook_secret_ciphertext']); }
        return $rows;
    }
    /** Internal provider credentials only; never pass this result to a view or logger. */
    public function credentials(string $provider,string $environment,bool $requireActive=true): array
    {
        self::validate($provider,$environment); $row=$this->row($provider,$environment);
        if(!$row || ($requireActive && !(int)$row['is_active']) || !$row['secret_ciphertext'] || !$row['webhook_secret_ciphertext']) throw new DomainException('Gateway indisponível.');
        return ['secret'=>$this->cipher->decrypt($row['secret_ciphertext'],self::AAD),'webhook_secret'=>$this->cipher->decrypt($row['webhook_secret_ciphertext'],self::AAD)];
    }
    private function row(string $provider,string $environment): ?array
    {
        $s=$this->pdo->prepare('SELECT * FROM billing_gateway_settings WHERE provider=? AND environment=?');$s->execute([$provider,$environment]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
}
