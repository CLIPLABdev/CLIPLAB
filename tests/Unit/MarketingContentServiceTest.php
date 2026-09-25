<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\AccountRepository;
use App\Repositories\MarketingContentRepository;
use App\Services\MarketingContentService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class MarketingContentServiceTest extends TestCase
{
    private PDO $pdo;
    private MarketingContentService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL, name TEXT NOT NULL, price_cents INTEGER NOT NULL, monthly_minutes INTEGER NOT NULL, credits INTEGER NOT NULL, features TEXT NOT NULL, is_active INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT NOT NULL, status TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE marketing_testimonials (slot INTEGER PRIMARY KEY, name TEXT NOT NULL, context TEXT NOT NULL, quote TEXT NOT NULL, result TEXT NULL, source_url TEXT NULL, authorization_confirmed INTEGER NOT NULL, published INTEGER NOT NULL, updated_by INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec("INSERT INTO users (role, status) VALUES ('admin', 'active'), ('user', 'active')");
        $this->service = new MarketingContentService(new AccountRepository($this->pdo), new MarketingContentRepository($this->pdo));
    }

    public function testPublicPlansUseOnlyActiveCatalogRowsAndNormalizeLimits(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO plans (slug, name, price_cents, monthly_minutes, credits, features, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute(['free', 'Gratuito', 0, 30, 10, '{"exports_hd":false}', 1]);
        $insert->execute(['pro', 'Pro', 4900, 300, 100, '{"exports_hd":true,"priority_processing":true}', 1]);
        $insert->execute(['legacy', 'Legado', 9900, 999, 999, '{}', 0]);

        self::assertSame([
            ['id' => 1, 'name' => 'Gratuito', 'slug' => 'free', 'price_cents' => 0, 'monthly_minutes' => 30, 'credits' => 10, 'features' => ['exports_hd' => false, 'priority_processing' => false, 'team_access' => false, 'limits' => ['max_upload_bytes' => 104857600, 'storage_bytes' => 1073741824]], 'description' => '', 'daily_credits' => 0],
            ['id' => 2, 'name' => 'Pro', 'slug' => 'pro', 'price_cents' => 4900, 'monthly_minutes' => 300, 'credits' => 100, 'features' => ['exports_hd' => true, 'priority_processing' => true, 'team_access' => false, 'limits' => ['max_upload_bytes' => 104857600, 'storage_bytes' => 1073741824]], 'description' => '', 'daily_credits' => 0],
        ], $this->service->publicPlans());
    }

    public function testPublicTestimonialsAreEmptyUntilAuthorizedAndPublished(): void
    {
        self::assertSame([], $this->service->publicTestimonials());
        $this->service->saveTestimonials(1, [
            ['name' => 'Ana', 'context' => 'Educadora', 'quote' => 'Revisão clara.', 'result' => 'Caso verificado', 'source_url' => 'https://example.test/caso', 'authorization_confirmed' => '1', 'published' => '1'],
            ['name' => 'Beto', 'context' => 'Podcast', 'quote' => 'Fluxo organizado.', 'authorization_confirmed' => '1', 'published' => '0'],
        ]);
        self::assertSame([['name' => 'Ana', 'context' => 'Educadora', 'quote' => 'Revisão clara.', 'result' => 'Caso verificado', 'source_url' => 'https://example.test/caso']], $this->service->publicTestimonials());
    }

    public function testSavingWithoutAuthorizationFailsAndPreservesPreviousState(): void
    {
        $this->service->saveTestimonials(1, [['name' => 'Ana', 'context' => 'Educadora', 'quote' => 'Original.', 'authorization_confirmed' => '1', 'published' => '1']]);
        try {
            $this->service->saveTestimonials(1, [['name' => 'Outra', 'context' => 'Criadora', 'quote' => 'Sem consentimento.', 'published' => '1']]);
            self::fail('A testimonial without authorization must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame([['name' => 'Ana', 'context' => 'Educadora', 'quote' => 'Original.']], $this->service->publicTestimonials());
        }
    }

    /** @dataProvider invalidPayloads */
    public function testIncompleteLongAndExcessTestimonialsAreRejected(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->saveTestimonials(1, $payload);
    }

    /** @return iterable<string,array{array<int,array<string,mixed>>}> */
    public function invalidPayloads(): iterable
    {
        yield 'missing context' => [[['name' => 'Ana', 'quote' => 'Texto', 'authorization_confirmed' => '1']]];
        yield 'name over 100 characters' => [[['name' => str_repeat('a', 101), 'context' => 'Criadora', 'quote' => 'Texto', 'authorization_confirmed' => '1']]];
        yield 'quote over 600 characters' => [[['name' => 'Ana', 'context' => 'Criadora', 'quote' => str_repeat('q', 601), 'authorization_confirmed' => '1']]];
        yield 'unsafe source URL' => [[['name' => 'Ana', 'context' => 'Criadora', 'quote' => 'Texto', 'source_url' => 'javascript:alert(1)', 'authorization_confirmed' => '1']]];
        yield 'result over 160 characters' => [[['name' => 'Ana', 'context' => 'Criadora', 'quote' => 'Texto', 'result' => str_repeat('r', 161), 'authorization_confirmed' => '1']]];
        yield 'more than three' => [[
            ['name' => 'A', 'context' => 'C', 'quote' => 'Q', 'authorization_confirmed' => '1'], ['name' => 'B', 'context' => 'C', 'quote' => 'Q', 'authorization_confirmed' => '1'],
            ['name' => 'C', 'context' => 'C', 'quote' => 'Q', 'authorization_confirmed' => '1'], ['name' => 'D', 'context' => 'C', 'quote' => 'Q', 'authorization_confirmed' => '1'],
        ]];
    }

    public function testNonAdminCannotReplaceTestimonials(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->saveTestimonials(2, []);
    }
}
