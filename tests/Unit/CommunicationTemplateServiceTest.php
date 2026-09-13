<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Communications\CommunicationTemplateService;
use PDO; use PHPUnit\Framework\TestCase;
final class CommunicationTemplateServiceTest extends TestCase {
 public function testInstallsMissingDefaultWithoutReplacingEditableTemplate():void{$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('CREATE TABLE communication_email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,locale TEXT,subject_template TEXT,html_template TEXT,text_template TEXT,is_active INTEGER,version INTEGER,updated_by INTEGER NULL,created_at DATETIME,updated_at DATETIME)');$pdo->exec("INSERT INTO communication_email_templates (event,locale,subject_template,html_template,text_template,is_active,version) VALUES ('auth.password_reset','pt-BR','Personalizado','<p>Oi</p>','Oi',1,7)");$s=new CommunicationTemplateService($pdo);$s->installDefaults();self::assertSame('Personalizado',$pdo->query("SELECT subject_template FROM communication_email_templates WHERE event='auth.password_reset' ORDER BY version DESC LIMIT 1")->fetchColumn());self::assertGreaterThan(1,(int)$pdo->query('SELECT COUNT(*) FROM communication_email_templates')->fetchColumn());}
 public function testRejectsScriptAndHeaderInjectionInEditableTemplate():void{$pdo=new PDO('sqlite::memory:');$s=new CommunicationTemplateService($pdo);$this->expectException(\InvalidArgumentException::class);$s->validate('assunto\r\nBcc:x','<script>alert(1)</script>','texto');}
}
