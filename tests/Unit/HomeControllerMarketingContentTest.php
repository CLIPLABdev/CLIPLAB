<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\HomeController;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class HomeControllerMarketingContentTest extends TestCase
{
    private string $views;

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliplab-home-' . bin2hex(random_bytes(6));
        mkdir($this->views);
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'home.php', '<?php echo json_encode([$publicPlans, $publicTestimonials], JSON_THROW_ON_ERROR);');
    }

    protected function tearDown(): void
    {
        @unlink($this->views . DIRECTORY_SEPARATOR . 'home.php');
        @rmdir($this->views);
    }

    public function testIndexPassesDynamicPlansAndTestimonialsToHome(): void
    {
        $service = new class { public function publicPlans(): array { return [['id' => 7, 'name' => 'Plano real']]; } public function publicTestimonials(): array { return [['name' => 'Ana', 'context' => 'Podcast', 'quote' => 'Real']]; } };
        $controller = new HomeController(new View($this->views), static fn () => $service);
        self::assertSame([[['id' => 7, 'name' => 'Plano real']], [['name' => 'Ana', 'context' => 'Podcast', 'quote' => 'Real']]], json_decode($controller->index()->body(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testLegacyConstructorAndDatabaseFailureRenderEmptyContentInsteadOfInventedPrices(): void
    {
        self::assertSame([[], []], json_decode((new HomeController(new View($this->views)))->index()->body(), true, 512, JSON_THROW_ON_ERROR));
        $controller = new HomeController(new View($this->views), static function () { throw new \RuntimeException('database unavailable'); });
        self::assertSame([[], []], json_decode($controller->index()->body(), true, 512, JSON_THROW_ON_ERROR));
    }
}
