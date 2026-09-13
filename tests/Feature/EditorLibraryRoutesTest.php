<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Controllers\EditorLibraryController;
use App\Core\{Csrf, Request, Router, Session, View};
use App\Middleware\AuthMiddleware;
use App\Repositories\{EditorLibraryRepository, UserRepository};
use App\Services\EditorLibraryService;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\EditorLibraryFixture;

final class EditorLibraryRoutesTest extends TestCase
{
    use EditorLibraryFixture;
    private string $root;
    private EditorLibraryService $service;
    private EditorLibraryRepository $repo;
    private Router $router;
    protected function setUp(): void
    {
        $_SESSION = []; Session::put('user_id', 1);
        $pdo = $this->libraryDatabase(); $this->repo = new EditorLibraryRepository($pdo);
        $this->root = sys_get_temp_dir() . '/clipforge-library-http-' . bin2hex(random_bytes(8)); mkdir($this->root);
        $this->service = new EditorLibraryService($this->repo, new LocalPrivateStorage($this->root, 2097152), static function (): void {}, static fn (): bool => true);
        $this->router = $this->routes($pdo);
    }
    private function routes(\PDO $pdo, ?callable $rate = null): Router
    {
        $router = new Router();
        $editorLibraryControllerFactory = fn (): EditorLibraryController => new EditorLibraryController(new View(), $this->service, null, $rate);
        $authenticated = new AuthMiddleware(new UserRepository($pdo));
        require dirname(__DIR__, 2) . '/routes/editor-library.php';
        return $router;
    }
    protected function tearDown(): void
    {
        $_SESSION = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }
    public function testCatalogGuestDeniedAndRouteCompositionDoesNotOpenDatabase(): void
    {
        $_SESSION = []; $router = new Router();
        $editorLibraryControllerFactory = static function (): void { self::fail('Guest invoked DB factory'); };
        require dirname(__DIR__, 2) . '/routes/editor-library.php';
        self::assertSame(401, $router->dispatch(Request::fake('GET', '/api/editor-library'))->status());
        self::assertSame('/login', $router->dispatch(Request::fake('GET', '/marca'))->header('Location'));
    }
    public function testNoJsTemplateSaveEditApplyRemovePersistsAcrossReads(): void
    {
        $input = ['_token' => Csrf::token(), 'name' => 'Minha assinatura', 'category' => 'clean', 'aspect_ratio' => '1:1', 'options' => ['style' => 'minimal', 'font_family' => 'Georgia']];
        self::assertSame(302, $this->router->dispatch(Request::fake('POST', '/templates', $input))->status());
        $catalog = json_decode($this->router->dispatch(Request::fake('GET', '/api/editor-library'))->body(), true);
        $id = $catalog['templates'][0]['id'];
        $page = $this->router->dispatch(Request::fake('GET', '/templates?edit=' . $id));
        self::assertSame(200, $page->status()); self::assertStringContainsString('Minha assinatura', $page->body());
        self::assertStringContainsString('method="post"', $page->body()); self::assertStringContainsString('name="options[font_family]"', $page->body());
        $input['id'] = (string) $id; $input['name'] = 'Editado';
        $this->router->dispatch(Request::fake('POST', '/templates', $input));
        self::assertSame('Editado', $this->repo->findOwned($id, 1)['name']);
        self::assertSame(302, $this->router->dispatch(Request::fake('POST', '/templates', ['_token' => Csrf::token(), 'action' => 'apply', 'id' => $id]))->status());
        self::assertSame('Georgia', $this->repo->kitForUser(1)['options']['font_family']);
        self::assertSame('1:1', $this->repo->kitForUser(1)['aspect_ratio']);
        $this->router->dispatch(Request::fake('POST', '/templates', ['_token' => Csrf::token(), 'action' => 'remove', 'id' => $id]));
        self::assertNull($this->repo->findOwned($id, 1));
    }
    public function testCsrfAndForeignIdsCannotMutateLibrary(): void
    {
        self::assertSame(419, $this->router->dispatch(Request::fake('POST', '/templates', ['name' => 'Forged']))->status());
        $id = $this->service->saveTemplate(2, ['name' => 'Segredo', 'options' => []]);
        self::assertSame(404, $this->router->dispatch(Request::fake('GET', '/templates?edit=' . $id))->status());
        foreach (['remove', 'apply', 'save'] as $action) {
            $response = $this->router->dispatch(Request::fake('POST', '/templates', ['_token' => Csrf::token(), 'action' => $action, 'id' => $id, 'name' => 'Hack']));
            self::assertSame(404, $response->status());
        }
        self::assertNotNull($this->repo->findOwned($id, 2));
    }
    public function testBrandFormSavesFavoritesAndEscapesTextWithoutInlineScript(): void
    {
        $id = $this->service->saveTemplate(1, ['name' => '<script>bad</script>', 'options' => []]);
        $response = $this->router->dispatch(Request::fake('POST', '/marca', ['_token' => Csrf::token(), 'favorites' => [(string) $id], 'options' => ['cta_text' => 'Inscreva-se'], 'aspect_ratio' => '16:9']));
        self::assertSame(302, $response->status()); self::assertSame([$id], $this->repo->kitForUser(1)['favorites']);
        $page = $this->router->dispatch(Request::fake('GET', '/marca'));
        self::assertSame('private, no-store', $page->header('Cache-Control'));
        self::assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $page->body());
        self::assertStringNotContainsString('<script>bad</script>', $page->body());
        self::assertStringContainsString('multipart/form-data', $page->body());
    }
    public function testPrivatePngEndpointChecksOwnershipAndReturnsActualBytes(): void
    {
        $path = $this->root . '/upload.png'; file_put_contents($path, $this->png());
        $id = $this->service->uploadLogo(1, ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK]);
        $response = $this->router->dispatch(Request::fake('GET', '/marca/logos/' . $id));
        self::assertSame(200, $response->status()); self::assertSame('image/png', $response->header('Content-Type'));
        self::assertSame('private, no-store', $response->header('Cache-Control')); self::assertSame($this->png(), $response->body());
        Session::put('user_id', 2);
        self::assertSame(404, $this->router->dispatch(Request::fake('GET', '/marca/logos/' . $id))->status());
    }
    public function testMutationRateLimitReturnsFeedbackBeforeSaving(): void
    {
        $controller = new EditorLibraryController(new View(), $this->service, null, static fn (): bool => false);
        $response = $controller->storeTemplates(Request::fake('POST', '/templates', ['_token' => Csrf::token(), 'name' => 'Teste']));
        self::assertSame(429, $response->status()); self::assertSame([], $this->repo->listOwned(1));
    }
}
