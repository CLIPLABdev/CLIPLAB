<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Request;
use App\Core\Router;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestDatabase;

final class MarketingContentRoutesTest extends TestCase
{
    private PDO $pdo;
    /** @var array{plan_id:int,admin_id:int,user_id:int,other_admin_id:int} */
    private array $ids;

    protected function setUp(): void
    {
        $this->pdo = AdminTestDatabase::create();
        $this->ids = AdminTestDatabase::seed($this->pdo);
        $this->pdo->exec('CREATE TABLE marketing_testimonials (
            slot INTEGER PRIMARY KEY, name TEXT NOT NULL, context TEXT NOT NULL, quote TEXT NOT NULL,
            result TEXT NULL, source_url TEXT NULL, authorization_confirmed INTEGER NOT NULL,
            published INTEGER NOT NULL, updated_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testContentRouteSeparatesGuestUserAndAdmin(): void
    {
        $router = $this->router();
        self::assertSame(302, $router->dispatch(Request::fake('GET', '/admin/conteudo'))->status());
        $_SESSION = ['user_id' => $this->ids['user_id']];
        self::assertSame(403, $router->dispatch(Request::fake('GET', '/admin/conteudo'))->status());
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $response = $router->dispatch(Request::fake('GET', '/admin/conteudo'));
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Conteúdo público', $response->body());
        self::assertStringContainsString('name="_token"', $response->body());
    }

    public function testContentMutationRequiresCsrfAndPublishesOnlyAuthorizedRecord(): void
    {
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $router = $this->router();
        $payload = ['testimonials' => [[
            'name' => 'Ana', 'context' => 'Podcast', 'quote' => 'Uso autorizado.',
            'result' => 'Relato verificável', 'source_url' => 'https://example.test/case',
            'authorization_confirmed' => '1', 'published' => '1',
        ]]];

        self::assertSame(419, $router->dispatch(Request::fake('POST', '/admin/conteudo', $payload))->status());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM marketing_testimonials')->fetchColumn());

        $payload['_token'] = str_repeat('c', 64);
        $response = $router->dispatch(Request::fake('POST', '/admin/conteudo', $payload));
        self::assertSame(302, $response->status());
        self::assertSame('/admin/conteudo', $response->header('Location'));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM marketing_testimonials WHERE authorization_confirmed = 1 AND published = 1')->fetchColumn());
    }

    public function testInvalidPostRendersBoundedDraftWithFieldHintAndPreservesPublishedState(): void
    {
        $this->pdo->prepare('INSERT INTO marketing_testimonials
            (slot, name, context, quote, result, source_url, authorization_confirmed, published, updated_by)
            VALUES (1, ?, ?, ?, NULL, NULL, 1, 1, ?)')
            ->execute(['Persistida', 'Podcast', 'Citação que já estava pública.', $this->ids['admin_id']]);
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $router = $this->router();

        $response = $router->dispatch(Request::fake('POST', '/admin/conteudo', [
            '_token' => str_repeat('c', 64),
            'testimonials' => [
                ['name' => 'Edição <um>', 'context' => 'Criadora', 'quote' => 'Primeira edição.', 'authorization_confirmed' => '1', 'published' => '1'],
                ['name' => 'Edição dois', 'context' => 'Educadora', 'quote' => 'Segunda edição.', 'authorization_confirmed' => '1'],
                ['name' => 'Edição três', 'context' => 'Podcast', 'quote' => 'Terceira edição.', 'published' => '1'],
            ],
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('value="Edição &lt;um&gt;"', $response->body());
        self::assertStringContainsString('value="Edição dois"', $response->body());
        self::assertStringContainsString('value="Edição três"', $response->body());
        self::assertStringContainsString('Confirme a autorização do relato 3 antes de salvar.', $response->body());
        self::assertMatchesRegularExpression('/name="testimonials\[2\]\[authorization_confirmed\]"[^>]*aria-invalid="true"[^>]*aria-describedby="testimonial-3-authorization_confirmed-error"/', $response->body());
        self::assertStringContainsString('id="testimonial-3-authorization_confirmed-error"', $response->body());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM marketing_testimonials')->fetchColumn());
        self::assertSame('Persistida', (string) $this->pdo->query('SELECT name FROM marketing_testimonials WHERE published = 1 AND authorization_confirmed = 1')->fetchColumn());
        self::assertSame('Citação que já estava pública.', (string) $this->pdo->query('SELECT quote FROM marketing_testimonials WHERE slot = 1')->fetchColumn());
    }

    private function router(): Router
    {
        $router = new Router();
        $pdo = $this->pdo;
        $adminPdoFactory = static fn (): PDO => $pdo;
        require dirname(__DIR__, 2) . '/routes/admin.php';
        return $router;
    }
}
