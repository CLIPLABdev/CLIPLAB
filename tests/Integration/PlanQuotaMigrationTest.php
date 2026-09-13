<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Plans\PlanLimits;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class PlanQuotaMigrationTest extends TestCase
{
    private PDO $pdo;
    private string $base;

    protected function setUp(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $this->base . '/database/migrations'))->run();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testAddsUsageTimestampAndCommercialPlanLimits(): void
    {
        $this->pdo->exec((string) file_get_contents($this->base . '/database/seeds/plans.sql'));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'usage_recorded_at'")->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'idx_projects_user_usage'")->fetchColumn());

        $expected = [
            'free' => [0, 30, 10, 104857600, 1073741824],
            'pro' => [1990, 300, 150, 524288000, 21474836480],
            'business' => [4990, 900, 500, 524288000, 107374182400],
        ];
        foreach ($this->pdo->query("SELECT slug, price_cents, monthly_minutes, credits, features FROM plans WHERE slug IN ('free','pro','business')")->fetchAll() as $row) {
            $limits = PlanLimits::fromFeatures(json_decode((string) $row['features'], true, 16, JSON_THROW_ON_ERROR))->toArray()['limits'];
            self::assertSame($expected[$row['slug']], [(int) $row['price_cents'], (int) $row['monthly_minutes'], (int) $row['credits'], $limits['max_upload_bytes'], $limits['storage_bytes']]);
            unset($expected[$row['slug']]);
        }
        self::assertSame([], $expected);
    }

    public function testSeedsDoNotOverwriteAdministrativePlanChanges(): void
    {
        $this->pdo->exec((string) file_get_contents($this->base . '/database/seeds/plans.sql'));
        $this->pdo->beginTransaction();
        $this->pdo->exec("UPDATE plans SET price_cents = 7777, monthly_minutes = 777 WHERE slug = 'pro'");
        $this->pdo->exec((string) file_get_contents($this->base . '/database/seeds/plans.sql'));
        self::assertSame(['price_cents' => 7777, 'monthly_minutes' => 777], $this->pdo->query("SELECT price_cents, monthly_minutes FROM plans WHERE slug = 'pro'")->fetch());
        $this->pdo->rollBack();
    }

    public function testNormalizationKeepsSupportedFlagsAndRemovesUnknownLegacyKeys(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->pdo->beginTransaction();
        $statement = $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, ?)');
        $statement->execute([
            'legacy-' . $suffix,
            'Legacy ' . $suffix,
            '{"exports_hd":true,"priority_processing":false,"surprise":true,"limits":{"max_upload_bytes":-1}}',
        ]);
        $planId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec((string) file_get_contents($this->base . '/database/migrations/202609060011_normalize_plan_limits.sql'));
        $features = (string) $this->pdo->query('SELECT features FROM plans WHERE id = ' . $planId)->fetchColumn();

        self::assertSame([
            'exports_hd' => true,
            'priority_processing' => false,
            'team_access' => false,
            'limits' => ['max_upload_bytes' => 104857600, 'storage_bytes' => 1073741824],
        ], PlanLimits::fromFeatures(json_decode($features, true, 16, JSON_THROW_ON_ERROR))->toArray());
        $this->pdo->rollBack();
    }
}
