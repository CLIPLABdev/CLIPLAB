<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Communications\CampaignService;
use App\Communications\CampaignUnsubscribeService;
use App\Security\SecretCipher;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class CampaignServiceTest extends TestCase
{
    private PDO $pdo;
    private SecretCipher $cipher;
    private DateTimeImmutable $now;
    private CampaignService $campaigns;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,name TEXT,email TEXT,role TEXT,status TEXT,plan_id INTEGER)');
        $this->pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY,name TEXT)');
        $this->pdo->exec('CREATE TABLE communication_preferences (user_id INTEGER,category TEXT,email_enabled INTEGER,marketing_opted_in_at TEXT,PRIMARY KEY(user_id,category))');
        $this->pdo->exec('CREATE TABLE communication_email_templates (id INTEGER PRIMARY KEY,event TEXT,subject_template TEXT,html_template TEXT,text_template TEXT,is_active INTEGER,version INTEGER)');
        $this->pdo->exec('CREATE TABLE communication_campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,template_id INTEGER,title TEXT,body TEXT,segment TEXT,plan_id INTEGER NULL,status TEXT,scheduled_at TEXT NULL,template_snapshot_ciphertext TEXT,recipient_cursor INTEGER DEFAULT 0,lease_until TEXT NULL,created_by INTEGER,created_at TEXT,updated_at TEXT,cancelled_at TEXT NULL,completed_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE communication_campaign_recipients (campaign_id INTEGER,user_id INTEGER,recipient TEXT,status TEXT,created_at TEXT,queued_at TEXT NULL,PRIMARY KEY(campaign_id,user_id))');
        $this->pdo->exec('CREATE TABLE communication_email_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,recipient TEXT,event TEXT,category TEXT,payload_ciphertext TEXT,dedupe_key TEXT UNIQUE,status TEXT,available_at TEXT,leased_until TEXT NULL)');
        $this->pdo->exec("INSERT INTO plans VALUES (1,'Free'),(2,'Pro')");
        $this->pdo->exec("INSERT INTO users VALUES (1,'Admin','admin@example.test','admin','active',1),(7,'Ana','ana@example.test','user','active',2),(8,'Bia','bia@example.test','user','active',2),(9,'Cris','cris@example.test','user','suspended',2),(10,'Dani','dani@example.test','user','active',1)");
        $this->pdo->exec("INSERT INTO communication_preferences VALUES (7,'marketing',1,'2026-09-01 00:00:00'),(8,'marketing',0,NULL),(9,'marketing',1,'2026-09-01 00:00:00'),(10,'marketing',1,'2026-09-01 00:00:00')");
        $this->pdo->exec("INSERT INTO communication_email_templates VALUES (4,'marketing.campaign','{{titulo}}','<p>{{conteudo}}</p>','{{conteudo}}',1,3)");
        $this->cipher = new SecretCipher(base64_encode(str_repeat('c', 32)));
        $this->now = new DateTimeImmutable('2026-09-07 12:00:00');
        $unsub = new CampaignUnsubscribeService($this->pdo, $this->cipher, 'https://cliplab.example', fn (): DateTimeImmutable => $this->now);
        $this->campaigns = new CampaignService($this->pdo, $this->cipher, $unsub, fn (): DateTimeImmutable => $this->now);
    }

    public function testCampaignFreezesSelectedTemplateAndQueuesOnlyCurrentOptedInSegment(): void
    {
        $id = $this->campaigns->create(1, ['name'=>'Oferta Pro','template_id'=>4,'title'=>'Novo','body'=>'Conteúdo','segment'=>'plan','plan_id'=>2]);
        $this->pdo->exec("UPDATE communication_email_templates SET html_template='<p>ALTERADO</p>',version=4 WHERE id=4");
        $this->campaigns->schedule(1, $id, $this->now);

        self::assertSame(1, $this->campaigns->queueBatch($id, 20));
        $row = $this->pdo->query('SELECT user_id,payload_ciphertext,dedupe_key,status FROM communication_email_outbox')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(7, (int) $row['user_id']);
        self::assertSame('campaign:' . $id . ':user:7', $row['dedupe_key']);
        $payload = json_decode($this->cipher->decrypt($row['payload_ciphertext'], 'clipforge:communications:v1'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['nome_usuario'=>'Ana','titulo'=>'Novo','conteudo'=>'Conteúdo'], $payload['variables']);
        self::assertSame('<p>{{conteudo}}</p>', $payload['template_snapshot']['html_template']);
        self::assertStringStartsWith('https://cliplab.example/cancelar-inscricao?token=', $payload['unsubscribe_url']);
        self::assertStringNotContainsString('ALTERADO', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testCancelStopsPendingCampaignOutboxAndQueueReplayDoesNotDuplicate(): void
    {
        $id = $this->campaigns->create(1, ['name'=>'Todos','template_id'=>4,'title'=>'Oi','body'=>'Texto','segment'=>'active']);
        $this->campaigns->schedule(1, $id, $this->now);
        self::assertSame(2, $this->campaigns->queueBatch($id, 20));
        self::assertSame(0, $this->campaigns->queueBatch($id, 20));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn());
        $this->pdo->exec("UPDATE communication_email_outbox SET status='leased',leased_until='2000-01-01 00:00:00' WHERE id=(SELECT MIN(id) FROM communication_email_outbox)");
        $this->campaigns->cancel(1, $id);
        self::assertSame('cancelled', $this->pdo->query('SELECT status FROM communication_campaigns WHERE id=' . $id)->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM communication_email_outbox WHERE status='cancelled' AND payload_ciphertext IS NULL")->fetchColumn());
    }

    public function testUnsubscribeTokenExpiresAndCancelsQueuedCampaignsIdempotently(): void
    {
        $token = (new CampaignUnsubscribeService($this->pdo, $this->cipher, 'https://cliplab.example', fn (): DateTimeImmutable => $this->now))->tokenFor(7);
        $this->pdo->exec("INSERT INTO communication_email_outbox (user_id,recipient,event,category,payload_ciphertext,dedupe_key,status,available_at) VALUES (7,'ana@example.test','marketing.campaign','marketing','cipher','campaign:99:user:7','pending','2026-09-07 12:00:00')");
        $unsub = new CampaignUnsubscribeService($this->pdo, $this->cipher, 'https://cliplab.example', fn (): DateTimeImmutable => $this->now);
        $unsub->unsubscribe($token);
        $unsub->unsubscribe($token);
        self::assertSame(0, (int) $this->pdo->query("SELECT email_enabled FROM communication_preferences WHERE user_id=7 AND category='marketing'")->fetchColumn());
        self::assertSame('cancelled', $this->pdo->query("SELECT status FROM communication_email_outbox WHERE dedupe_key='campaign:99:user:7'")->fetchColumn());
        $this->now = $this->now->modify('+366 days');
        $this->expectException(\InvalidArgumentException::class);
        $unsub->resolve($token);
    }

    public function testCancelledCampaignCannotBeRescheduledOrOverwritten(): void
    {
        $id = $this->campaigns->create(1, ['name'=>'Concorrente','template_id'=>4,'title'=>'Original','body'=>'Texto','segment'=>'active']);
        $this->campaigns->cancel(1, $id);
        try {$this->campaigns->schedule(1, $id, $this->now);self::fail('Scheduling a cancelled campaign must fail.');} catch (\InvalidArgumentException) {}
        try {$this->campaigns->update(1, $id, ['name'=>'Concorrente','template_id'=>4,'title'=>'Sobrescrito','body'=>'Novo','segment'=>'active']);self::fail('Updating a cancelled campaign must fail.');} catch (\InvalidArgumentException) {}
        self::assertSame(['cancelled','Original'], $this->pdo->query('SELECT status,title FROM communication_campaigns WHERE id=' . $id)->fetch(PDO::FETCH_NUM));
    }
}
