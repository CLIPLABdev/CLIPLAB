<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testMatchesNamedRouteParameter(): void
    {
        $router = new Router();
        $router->get('/projects/{id}', fn (Request $request, array $parameters) => Response::text($parameters['id']));

        self::assertSame('42', $router->dispatch(Request::fake('GET', '/projects/42'))->body());
    }

    public function testReturnsMethodNotAllowedForARegisteredPathWithDifferentMethod(): void
    {
        $router = new Router();
        $router->post('/projects', fn () => Response::text('created'));

        $response = $router->dispatch(Request::fake('GET', '/projects'));

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->header('Allow'));
    }

    public function testRunsRouteMiddlewareAroundTheHandler(): void
    {
        $router = new Router();
        $router->get(
            '/reports',
            fn () => Response::text('report'),
            [fn (Request $request, callable $next) => $next($request)->withHeader('X-Route-Middleware', 'applied')]
        );

        self::assertSame('applied', $router->dispatch(Request::fake('GET', '/reports'))->header('X-Route-Middleware'));
    }

    public function testRequestExposesParsedPathQueryInputAndHeaders(): void
    {
        $request = Request::fake('post', '/search?q=clip', ['title' => 'Launch'], ['X-Request-Id' => 'abc']);

        self::assertSame('POST', $request->method());
        self::assertSame('/search', $request->path());
        self::assertSame('clip', $request->query('q'));
        self::assertSame('Launch', $request->input('title'));
        self::assertSame('abc', $request->header('x-request-id'));
        self::assertTrue($request->isMethod('POST'));
    }

    public function testResponseRedirectCarriesLocationAndSeeOtherStatus(): void
    {
        $response = Response::redirect('/login');

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertSame('', $response->body());
    }

    public function testSessionStoresReadsFlashesAndForgetsValues(): void
    {
        Session::put('user_id', 9);
        Session::flash('notice', 'Saved');

        self::assertSame(9, Session::get('user_id'));
        self::assertSame('Saved', Session::pull('notice'));
        self::assertNull(Session::pull('notice'));

        Session::forget('user_id');

        self::assertFalse(Session::has('user_id'));
    }

    public function testViewRendersTemplateWithEscapedDataAvailableToIt(): void
    {
        $directory = sys_get_temp_dir() . '/view-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/welcome.php', '<h1><?= e($name) ?></h1>');

        try {
            $response = (new View($directory))->render('welcome', ['name' => '<ClipLab>']);

            self::assertSame('<h1>&lt;ClipLab&gt;</h1>', $response->body());
        } finally {
            unlink($directory . '/welcome.php');
            rmdir($directory);
        }
    }

    public function testAuthMiddlewareRedirectsGuestsToLogin(): void
    {
        $response = (new AuthMiddleware())->handle(Request::fake('GET', '/dashboard'), fn () => Response::text('dashboard'));

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function testAuthMiddlewareAcceptsOnlyAnExistingActiveSessionUser(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $pdo->exec("INSERT INTO users (id, status) VALUES (7, 'active'), (8, 'suspended')");
        $middleware = new AuthMiddleware(static fn (): UserRepository => new UserRepository($pdo));

        $_SESSION = ['user_id' => 7];
        $active = $middleware->handle(Request::fake('GET', '/dashboard'), fn () => Response::text('dashboard'));
        $_SESSION = ['user_id' => 8];
        $suspended = $middleware->handle(Request::fake('GET', '/dashboard'), fn () => Response::text('dashboard'));

        self::assertSame(200, $active->status());
        self::assertSame(302, $suspended->status());
        self::assertSame('/login', $suspended->header('Location'));
        self::assertFalse(Session::has('user_id'));
    }

    public function testAdminNavigationIsRefreshedFromTrustedIdentityOnEachRequest(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $pdo->exec("INSERT INTO users VALUES (7, 'active')");
        $isAdmin = true;
        $middleware = new AuthMiddleware(new UserRepository($pdo), static function (int $id) use (&$isAdmin): bool { return $id === 7 && $isAdmin; });
        Session::put('user_id', 7);
        $middleware->handle(Request::fake('GET', '/dashboard'), static fn () => Response::text('ok'));
        self::assertTrue(Session::get('admin_navigation'));
        $isAdmin = false;
        $middleware->handle(Request::fake('GET', '/dashboard'), static fn () => Response::text('ok'));
        self::assertFalse(Session::get('admin_navigation'));
        $pdo->exec("UPDATE users SET status = 'suspended'");
        Session::put('admin_navigation', true);
        $middleware->handle(Request::fake('GET', '/dashboard'), static fn () => Response::text('ok'));
        self::assertFalse(Session::has('admin_navigation'));
    }

    public function testGuestMiddlewareRedirectsAuthenticatedUsersToDashboard(): void
    {
        Session::put('user_id', 9);

        $response = (new GuestMiddleware())->handle(Request::fake('GET', '/login'), fn () => Response::text('login'));

        self::assertSame(302, $response->status());
        self::assertSame('/dashboard', $response->header('Location'));
    }

    public function testSecurityHeadersMiddlewareAddsExplicitCspForApprovedCdns(): void
    {
        $response = (new SecurityHeadersMiddleware())->handle(Request::fake('GET', '/'), fn () => Response::html('<main>ok</main>'));

        self::assertSame("default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; worker-src 'self'", $response->header('Content-Security-Policy'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }
}
