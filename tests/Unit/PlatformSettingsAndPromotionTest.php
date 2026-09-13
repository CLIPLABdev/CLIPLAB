<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\PlatformSettingsRepository;
use App\Repositories\PromotionRepository;
use App\Services\PlatformSettingsService;
use App\Services\PromotionService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class PlatformSettingsAndPromotionTest extends TestCase
{
    public function testBrandingAcceptsOnlyLocalRasterAssets(): void
    {
        $pdo = $this->pdo();
        $settings = new PlatformSettingsService(new PlatformSettingsRepository($pdo));

        $settings->save(['name' => 'Nova Plataforma', 'description' => 'Vídeos com clareza.', 'logo_url' => '/assets/images/logo.png', 'favicon_url' => '/assets/images/favicon.ico']);

        self::assertSame('Nova Plataforma', $settings->branding()['name']);
        $this->expectException(\InvalidArgumentException::class);
        $settings->save(['name' => 'Nova Plataforma', 'description' => '', 'logo_url' => 'https://outside.test/logo.png', 'favicon_url' => '/assets/images/favicon.svg']);
    }

    public function testEmptySettingsReturnCompleteBrandingShape(): void
    {
        $branding=(new PlatformSettingsService(new PlatformSettingsRepository($this->pdo())))->branding();
        self::assertSame('ClipForge',$branding['name']); self::assertNull($branding['logo_url']); self::assertNull($branding['favicon_url']);
    }

    public function testPromotionVisibilityHonorsStatusPlanScheduleAndAudience(): void
    {
        $pdo = $this->pdo();
        $repository = new PromotionRepository($pdo);
        $service = new PromotionService($repository, new DateTimeImmutable('2026-09-07 12:00:00 UTC'));
        $repository->create(['title' => 'Plano Pro', 'body' => 'Mais minutos.', 'cta_label' => 'Ver plano', 'cta_url' => '/conta/plano', 'image_url' => null, 'placement' => 'dashboard', 'audience' => 'plan', 'plan_id' => 4, 'user_id' => null, 'starts_at' => '2026-09-07 11:00:00', 'ends_at' => '2026-09-08 11:00:00', 'is_active' => true]);
        $repository->create(['title' => 'Pausado', 'body' => 'Não aparece', 'cta_label' => '', 'cta_url' => '', 'image_url' => null, 'placement' => 'dashboard', 'audience' => 'all', 'plan_id' => null, 'user_id' => null, 'starts_at' => null, 'ends_at' => null, 'is_active' => false]);

        self::assertCount(1, $service->visibleForUser(8, ['status' => 'active', 'plan_id' => 4]));
        self::assertSame([], $service->visibleForUser(8, ['status' => 'active', 'plan_id' => 3]));
        self::assertSame([], $service->visibleForUser(8, ['status' => 'suspended', 'plan_id' => 4]));
    }

    public function testPromotionCreationRejectsUnsafeCtaLinks(): void
    {
        $pdo = $this->pdo();
        $service = new PromotionService(new PromotionRepository($pdo), new DateTimeImmutable('2026-09-07 12:00:00 UTC'));

        $this->expectException(\InvalidArgumentException::class);
        $service->create(['title' => 'Unsafe', 'body' => 'Unsafe', 'cta_label' => 'Abrir', 'cta_url' => 'javascript:alert(1)', 'image_url' => null, 'placement' => 'dashboard', 'audience' => 'all', 'plan_id' => null, 'user_id' => null, 'starts_at' => null, 'ends_at' => null, 'is_active' => true]);
    }

    public function testPromotionRejectsProtocolRelativeAndNonHttpsLinksAndInvalidDates(): void
    {
        $service=new PromotionService(new PromotionRepository($this->pdo()),new DateTimeImmutable('2026-09-07 12:00:00 UTC'));
        foreach (['//evil.test','ftp://safe.test/a'] as $url) { try { $service->create(['title'=>'Safe','body'=>'Safe','cta_url'=>$url,'placement'=>'dashboard','audience'=>'all','is_active'=>true]); self::fail('unsafe URL accepted'); } catch (\InvalidArgumentException) { self::addToAssertionCount(1); } }
        $this->expectException(\InvalidArgumentException::class); $service->create(['title'=>'Safe','body'=>'Safe','placement'=>'dashboard','audience'=>'all','starts_at'=>'not-a-date','is_active'=>true]);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE platform_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE promotions (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL, cta_label TEXT NULL, cta_url TEXT NULL, image_url TEXT NULL, delivery_kind TEXT NOT NULL DEFAULT "banner", placement TEXT NOT NULL, audience TEXT NOT NULL, plan_id INTEGER NULL, user_id INTEGER NULL, starts_at TEXT NULL, ends_at TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        return $pdo;
    }

    public function testPromotionUpdateUsesSameLengthAndTypeRulesAsCreation(): void
    {
        $service=new PromotionService(new PromotionRepository($this->pdo()),new DateTimeImmutable('2026-09-07 12:00:00 UTC'));
        $base=['title'=>'Título','body'=>'Mensagem','placement'=>'dashboard','audience'=>'all','is_active'=>true];
        $id=$service->create($base);
        foreach (['title'=>str_repeat('x',121),'body'=>str_repeat('x',501),'audience'=>[]] as $field=>$value) {
            try { $service->update($id,array_replace($base,[$field=>$value])); self::fail('Invalid update accepted'); }
            catch (\InvalidArgumentException $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }
}
