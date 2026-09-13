<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Controllers\{EditorLibraryController, PublicationPreparationController};
use App\Core\{Csrf, Request, Session, View};
use App\Repositories\{EditorLibraryRepository, PublicationPreparationRepository, ThumbnailRepository};
use App\Services\{EditorLibraryService, PublicationPreparationService};
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\{EditorLibraryFixture, ThumbnailTestDatabase};

final class FormRecoveryTest extends TestCase
{
    use EditorLibraryFixture;

    protected function tearDown(): void { $_SESSION = []; }

    private function library(?callable $rate = null): array
    {
        Session::put('user_id', 1);
        $repo = new EditorLibraryRepository($this->libraryDatabase());
        $service = new EditorLibraryService($repo, new LocalPrivateStorage(sys_get_temp_dir(), 2097152), static function (): void {});
        return [new EditorLibraryController(new View(), $service, null, $rate), $repo, $service];
    }

    private function publication(): array
    {
        Session::put('user_id', 7); $pdo = ThumbnailTestDatabase::create();
        $pdo->exec("INSERT INTO clip_thumbnails(id,clip_id,user_id,render_revision,kind,offset_seconds,options_json,status,object_key,size_bytes) VALUES(5,1,7,1,'design',2,'{}','ready','private/owned.jpg',100),(6,2,8,1,'design',3,'{}','ready','private/foreign.jpg',100)");
        $thumbnails = new ThumbnailRepository($pdo);
        $service = new PublicationPreparationService($pdo, new PublicationPreparationRepository($pdo), $thumbnails);
        return [new PublicationPreparationController(new View(), $thumbnails, $service), $service, $pdo];
    }

    private function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument(); $old = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors(); libxml_use_internal_errors($old);
        return new \DOMXPath($doc);
    }

    private function value(\DOMXPath $dom, string $form, string $name): string
    {
        $node = $dom->query($form . '//*[@name="' . $name . '"]')->item(0);
        self::assertNotNull($node, 'Missing form control ' . $name);
        if ($node->nodeName === 'textarea') return $node->textContent;
        if ($node->nodeName === 'select') {
            $option = $dom->query('option[@selected]', $node)->item(0) ?? $dom->query('option', $node)->item(0);
            return $option ? $option->getAttribute('value') : '';
        }
        return $node->getAttribute('value');
    }

    public function testLibraryValidationRetainsOwnedEditIdentityAndEditableInvalidValues(): void
    {
        [$controller, $repo, $service] = $this->library();
        $id = $service->saveTemplate(1, ['name' => 'Original']);
        $draft = ['_token' => Csrf::token(), 'id' => (string) $id, 'name' => 'Rascunho <script>seguro</script>', 'category' => 'podcast', 'aspect_ratio' => '1:1',
            'options' => ['font_size' => 'forty', 'font_family' => 'Fonte inválida', 'color' => 'cor inválida', 'title' => 'Texto com "aspas"', 'cta_text' => 'Meu CTA']];
        $response = $controller->storeTemplates(Request::fake('POST', '/templates', $draft));
        self::assertSame(422, $response->status()); $dom = $this->dom($response->body()); $form = '//form[@data-library-form]';
        self::assertSame((string) $id, $this->value($dom, $form, 'id'));
        self::assertSame($draft['name'], $this->value($dom, $form, 'name'));
        foreach ($draft['options'] as $key => $value) self::assertSame($value, $this->value($dom, $form, 'options[' . $key . ']'));
        self::assertSame('text', $dom->query($form . '//input[@name="options[font_size]"]')->item(0)->getAttribute('type'));
        self::assertSame('text', $dom->query($form . '//input[@name="options[color]"]')->item(0)->getAttribute('type'));
        self::assertSame('Original', $repo->findOwned($id, 1)['name']); self::assertCount(1, $repo->listOwned(1));
        self::assertStringNotContainsString('<script>seguro</script>', $response->body());
    }

    public function testLibraryRateLimitRetainsEditIdAndBrandFavoritesWithoutSaving(): void
    {
        [$controller, $repo, $service] = $this->library(static fn (): bool => false);
        $id = $service->saveTemplate(1, ['name' => 'Original']);
        $r = $controller->storeTemplates(Request::fake('POST', '/templates', ['_token' => Csrf::token(), 'id' => (string) $id, 'name' => 'Não perder', 'options' => ['watermark' => 'Marca pendente']]));
        self::assertSame(429, $r->status()); $dom = $this->dom($r->body());
        self::assertSame((string) $id, $this->value($dom, '//form[@data-library-form]', 'id'));
        self::assertSame('Não perder', $this->value($dom, '//form[@data-library-form]', 'name'));
        self::assertSame('Original', $repo->findOwned($id, 1)['name']);
        $r = $controller->storeBrand(Request::fake('POST', '/marca', ['_token' => Csrf::token(), 'favorites' => [(string) $id], 'options' => ['cta_text' => 'CTA pendente']]));
        self::assertSame(429, $r->status()); $dom = $this->dom($r->body());
        self::assertSame('CTA pendente', $this->value($dom, '//form[@action="/marca"]', 'options[cta_text]'));
        self::assertSame(1, $dom->query('//form[@action="/marca"]//input[@name="favorites[]"][@checked][@value="' . $id . '"]')->length);
        self::assertSame([], $repo->kitForUser(1)['favorites']);
    }

    public function testRecoveryCannotTurnForeignTemplateIntoANewOwnedForm(): void
    {
        foreach ([null, static fn (): bool => false] as $rate) {
            [$controller, $repo, $service] = $this->library($rate);
            $foreign = $service->saveTemplate(2, ['name' => 'Foreign secret']);
            $r = $controller->storeTemplates(Request::fake('POST', '/templates', ['_token' => Csrf::token(), 'id' => (string) $foreign, 'name' => 'Nope', 'options' => ['font_size' => 'bad']]));
            self::assertSame(404, $r->status()); self::assertStringNotContainsString('Foreign secret', $r->body());
            self::assertSame([], $repo->listOwned(1));
        }
    }

    public function testPublicationValidationRetainsLongNewDraftAndThumbnailWithoutPersisting(): void
    {
        [$controller, $service, $pdo] = $this->publication();
        $draft = ['publication_id' => '0', 'version' => '0', 'platform' => 'instagram', 'thumbnail_id' => '5', 'title' => 'Título <script>x</script>',
            'description' => str_repeat('Texto longo ', 500), 'caption' => 'Legenda pendente', 'cta' => 'Meu CTA', 'hashtags' => '#um, #dois'];
        $r = $controller->store(Request::fake('POST', '/clips/1/publicacao', $draft), ['id' => '1']);
        self::assertSame(422, $r->status()); $dom = $this->dom($r->body()); $form = '//article[@id="publicacao-0"]/form[@action="/clips/1/publicacao"]';
        foreach ($draft as $key => $value) self::assertSame($value, $this->value($dom, $form, $key), $key);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM publication_preparations')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM publication_events')->fetchColumn());
        self::assertStringNotContainsString('<script>x</script>', $r->body());
    }

    public function testPublicationConflictKeepsOriginalVersionAndOnlyAffectedDraft(): void
    {
        [$controller, $service, $pdo] = $this->publication();
        $id = $service->save(1, 7, 0, 0, ['title' => 'Original'], null);
        $other = $service->save(1, 7, 0, 0, ['title' => 'Outra preparação'], null);
        $service->save(1, 7, $id, 1, ['title' => 'Salvo em outra aba'], null);
        $draft = ['publication_id' => (string) $id, 'version' => '1', 'platform' => 'youtube_shorts', 'thumbnail_id' => '5', 'title' => 'Meu rascunho divergente',
            'description' => str_repeat('Meu texto. ', 100), 'caption' => 'Minha legenda', 'cta' => 'CTA', 'hashtags' => '#um #dois'];
        $r = $controller->store(Request::fake('POST', '/clips/1/publicacao', $draft), ['id' => '1']);
        self::assertSame(409, $r->status()); $dom = $this->dom($r->body()); $form = '//article[@id="publicacao-' . $id . '"]/form[@action="/clips/1/publicacao"]';
        foreach ($draft as $key => $value) self::assertSame($value, $this->value($dom, $form, $key), $key);
        self::assertSame('Outra preparação', $this->value($dom, '//article[@id="publicacao-' . $other . '"]/form[@action="/clips/1/publicacao"]', 'title'));
        self::assertStringContainsString('versão enviada: 1', $r->body()); self::assertStringContainsString('versão salva: 2', $r->body());
        self::assertSame('Salvo em outra aba', $service->findOwned($id, 7)['metadata']['title']);
        self::assertSame(2, (int) $service->findOwned($id, 7)['version']);
        self::assertCount(2, $service->history($id, 7));
        self::assertSame(409, $controller->store(Request::fake('POST', '/clips/1/publicacao', $draft), ['id' => '1'])->status());
    }

    public function testInvalidPublicationDraftCannotRevealForeignOrDifferentClipIdentity(): void
    {
        [$controller, $service] = $this->publication();
        $foreign = $service->save(2, 8, 0, 0, ['title' => 'Foreign publication'], null);
        $r = $controller->store(Request::fake('POST', '/clips/1/publicacao', ['publication_id' => (string) $foreign, 'version' => '1', 'description' => str_repeat('x', 5001)]), ['id' => '1']);
        self::assertSame(404, $r->status()); self::assertStringNotContainsString('Foreign publication', $r->body());
        self::assertSame('Foreign publication', $service->findOwned($foreign, 8)['metadata']['title']);
    }
}
