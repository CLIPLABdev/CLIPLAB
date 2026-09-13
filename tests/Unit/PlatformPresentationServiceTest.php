<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Services\PlatformPresentationService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class PlatformPresentationServiceTest extends TestCase
{
    private PDO $pdo;
    private PlatformPresentationService $service;
    protected function setUp(): void
    {
        $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE platform_settings(setting_key TEXT,setting_value TEXT)');
        $this->pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,plan_id INTEGER,status TEXT,avatar_path TEXT)');
        $this->pdo->exec("INSERT INTO users VALUES (1,2,'active',NULL),(2,3,'active',NULL),(3,2,'suspended',NULL)");
        $this->pdo->exec('CREATE TABLE communication_notifications(id INTEGER PRIMARY KEY,user_id INTEGER,read_at TEXT)');
        $this->pdo->exec('INSERT INTO communication_notifications VALUES(1,1,NULL),(2,2,NULL),(3,2,NULL)');
        $this->pdo->exec('CREATE TABLE promotions(id INTEGER PRIMARY KEY,title TEXT,body TEXT,cta_label TEXT,cta_url TEXT,image_url TEXT,placement TEXT,audience TEXT,plan_id INTEGER,user_id INTEGER,starts_at TEXT,ends_at TEXT,is_active INTEGER,delivery_kind TEXT,updated_at TEXT)');
        $this->pdo->exec("INSERT INTO promotions VALUES(1,'Plano 2','Mensagem','Ver','/conta/plano',NULL,'dashboard','plan',2,NULL,NULL,NULL,1,'banner','2026-09-07 00:00:00'),(2,'Outro usuário','Mensagem','Ver','/conta/plano',NULL,'dashboard','user',NULL,2,NULL,NULL,1,'popup','2026-09-07 00:00:00')");
        $this->service=new PlatformPresentationService($this->pdo,dirname(__DIR__,2).'/public',['communications'=>true],new DateTimeImmutable('2026-09-07 12:00:00 UTC'));
    }
    public function testGuestContextHasBrandingButNoPrivateData(): void
    {
        $context=$this->service->context('home.index',[]);
        self::assertSame('ClipForge',$context['platformBranding']['name']);
        self::assertSame([],$context['currentPromotions']);
        self::assertSame(0,$context['notificationUnread']);
    }
    public function testAuthenticatedContextIsSegmentedAndDoesNotExposeRawUserFields(): void
    {
        $context=$this->service->context('dashboard.index',['user'=>['id'=>1]]);
        self::assertSame(1,$context['notificationUnread']);
        self::assertSame([1],array_column($context['currentPromotions'],'id'));
        self::assertArrayNotHasKey('avatar_path',$context);
        self::assertSame([], $this->service->context('profile.edit',['user'=>['id'=>1]])['currentPromotions']);
        self::assertSame([], $this->service->context('dashboard.index',['user'=>['id'=>3]])['currentPromotions']);
    }
    public function testMissingOrUnsafeAssetsAndLinksAreNotRendered(): void
    {
        $this->pdo->exec("INSERT INTO platform_settings VALUES('logo_url','/assets/images/missing.png'),('favicon_url','javascript:alert(1)')");
        $this->pdo->exec("UPDATE promotions SET cta_url='javascript:alert(1)',image_url='https://tracker.example.test/pixel.png' WHERE id=1");
        $context=$this->service->context('dashboard.index',['user'=>['id'=>1]]);
        self::assertNull($context['platformBranding']['logo_url']);
        self::assertNull($context['platformBranding']['favicon_url']);
        self::assertNull($context['currentPromotions'][0]['cta_url']);
        self::assertNull($context['currentPromotions'][0]['image_url']);
    }
}
