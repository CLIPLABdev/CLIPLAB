<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Communications\CommunicationMailSettingsService;
use App\Security\SecretCipher;
use PDO;use PHPUnit\Framework\TestCase;
final class CommunicationMailSettingsTest extends TestCase {
    public function testBlankPasswordPreservesOnlySameServerCredentialsAndPublicStateNeverExposesSecrets():void {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE communication_mail_settings(id INTEGER PRIMARY KEY,from_address TEXT,from_name TEXT,smtp_host TEXT,smtp_port INTEGER,smtp_encryption TEXT,smtp_username TEXT,smtp_password_ciphertext TEXT,smtp_timeout INTEGER,last_test_status TEXT,last_test_code TEXT,last_tested_at TEXT,updated_by INTEGER)');
        $service=new CommunicationMailSettingsService($pdo,new SecretCipher(base64_encode(str_repeat('k',32))),[]);
        $input=['from_address'=>'from@example.test','from_name'=>'App','smtp_host'=>'smtp.example.test','smtp_port'=>587,'smtp_encryption'=>'tls','smtp_username'=>'user','smtp_password'=>'SECRET','smtp_timeout'=>10];
        $service->save(1,$input);$input['smtp_password']='';$service->save(1,$input);
        self::assertSame('SECRET',$service->effective()['smtp_password']);self::assertTrue($service->publicState()['has_password']);self::assertStringNotContainsString('SECRET',json_encode($service->publicState()));
        $input['smtp_host']='other.example.test';$this->expectException(\InvalidArgumentException::class);$service->save(1,$input);
    }
}
