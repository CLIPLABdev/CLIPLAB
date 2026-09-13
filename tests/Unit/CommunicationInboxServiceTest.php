<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Communications\CommunicationInboxService;
use PDO;
use PHPUnit\Framework\TestCase;
final class CommunicationInboxServiceTest extends TestCase {
 public function testMarketingOptInIsExplicitAndDoesNotDisableTransactionalChannels(): void {
  $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->exec("CREATE TABLE communication_preferences (user_id INTEGER, category TEXT, email_enabled INTEGER, in_app_enabled INTEGER, marketing_opted_in_at DATETIME NULL, unsubscribe_token_hash TEXT NULL, updated_at DATETIME, PRIMARY KEY(user_id,category))");
  $service=new CommunicationInboxService($pdo);
  $service->setMarketingOptIn(7,true);
  self::assertTrue($service->marketingOptedIn(7));
  self::assertTrue($service->emailEnabled(7,'billing'));
  $service->setMarketingOptIn(7,false);
  self::assertFalse($service->marketingOptedIn(7));
  self::assertTrue($service->emailEnabled(7,'billing'));
 }
}
