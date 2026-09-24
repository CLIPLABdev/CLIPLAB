# Fundação SaaS de Vídeo — Fase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir uma aplicação PHP 8/MySQL instalável na Hostinger com landing page premium, autenticação segura, recuperação de senha e dashboard alimentado pelo banco.

**Architecture:** Monólito modular com front controller, roteador próprio pequeno, controllers finos, serviços de domínio e repositórios PDO. Toda mutação passa por CSRF e validação; páginas privadas passam por middleware de autenticação; migrations rodam somente via CLI.

**Tech Stack:** PHP 8.1+, MySQL 8+, PDO, HTML5, CSS3, JavaScript puro, Bootstrap 5 via CDN, Lucide Icons via CDN, PHPUnit 10 apenas em desenvolvimento.

**Spec:** `docs/superpowers/specs/2026-09-03-fase-1-fundacao-saas-video-design.md`

## Global Constraints

- Produção deve funcionar em hospedagem compartilhada Hostinger sem Node.js, Docker, Redis, WebSocket ou processos permanentes.
- Código de aplicação, configuração, migrations e storage não podem ser servidos diretamente pela web.
- Chaves, senhas e tokens nunca podem aparecer em HTML, JavaScript ou logs.
- Persistência usa PDO e prepared statements; mutações usam CSRF; saída HTML usa escape centralizado.
- O frontend é desktop first, responsivo e usa a paleta `#080808`, `#101010`, `#151515`, `#1C1C1C`, `#262626`, `#FFFFFF`, `#A1A1AA`, `#71717A`, `#7C3AED`, `#A855F7`, `#22C55E`.
- Dados do dashboard devem vir do banco; estados vazios são permitidos, dados fictícios não.

---

### Task 1: Bootstrap, configuração e suíte de testes

**Files:**
- Create: `composer.json`
- Create: `.env.example`
- Create: `.gitignore`
- Create: `bootstrap/app.php`
- Create: `app/Core/Env.php`
- Create: `app/Core/Config.php`
- Create: `app/Helpers/functions.php`
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`
- Test: `tests/Unit/EnvTest.php`

**Interfaces:**
- Produces: `Env::load(string $path): void`, `Env::get(string $key, ?string $default = null): ?string`, `Config::get(string $key, mixed $default = null): mixed`, `e(?string $value): string`.

- [ ] **Step 1: Criar manifesto e teste falhando do carregador de ambiente**

```json
{
  "name": "cliplab/app",
  "type": "project",
  "require": {"php": ">=8.1", "ext-pdo": "*", "ext-mbstring": "*"},
  "require-dev": {"phpunit/phpunit": "^10.5"},
  "autoload": {"psr-4": {"App\\": "app/"}, "files": ["app/Helpers/functions.php"]},
  "autoload-dev": {"psr-4": {"Tests\\": "tests/"}}
}
```

```php
public function testLoadsQuotedAndPlainValues(): void
{
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, "APP_NAME=ClipLab\nAPP_ENV=\"testing\"\n");
    Env::load($file);
    self::assertSame('ClipLab', Env::get('APP_NAME'));
    self::assertSame('testing', Env::get('APP_ENV'));
    unlink($file);
}
```

- [ ] **Step 2: Instalar dependências e confirmar a falha**

Run: `composer install && vendor/bin/phpunit tests/Unit/EnvTest.php`

Expected: FAIL porque `App\Core\Env` ainda não existe.

- [ ] **Step 3: Implementar ambiente, configuração e escape HTML**

```php
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            if (getenv($key) === false) putenv("{$key}={$value}");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
```

```php
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

- [ ] **Step 4: Rodar testes e gerar autoload otimizado**

Run: `composer dump-autoload -o && vendor/bin/phpunit tests/Unit/EnvTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add composer.json .env.example .gitignore bootstrap app/Core app/Helpers phpunit.xml tests
git commit -m "build: bootstrap php application"
```

### Task 2: Banco, migrations e dados iniciais

**Files:**
- Create: `app/Core/Database.php`
- Create: `app/Core/Migrator.php`
- Create: `config/database.php`
- Create: `database/migrations/202609030001_create_foundation_tables.sql`
- Create: `database/seeds/plans.sql`
- Create: `bin/migrate.php`
- Test: `tests/Unit/MigratorTest.php`
- Test: `tests/Integration/DatabaseMigrationTest.php`

**Interfaces:**
- Consumes: `Config::get()` from Task 1.
- Produces: `Database::connection(): PDO`, `Migrator::__construct(PDO $pdo, string $directory)`, `Migrator::run(): array<string>`.

- [ ] **Step 1: Escrever teste falhando de migration executada uma vez**

```php
public function testRunsEachMigrationOnlyOnce(): void
{
    $pdo = new PDO('sqlite::memory:');
    $dir = sys_get_temp_dir() . '/migrations-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/001.sql', 'CREATE TABLE sample (id INTEGER PRIMARY KEY);');
    $migrator = new Migrator($pdo, $dir);
    self::assertSame(['001.sql'], $migrator->run());
    self::assertSame([], $migrator->run());
}
```

- [ ] **Step 2: Confirmar falha**

Run: `vendor/bin/phpunit tests/Unit/MigratorTest.php`

Expected: FAIL porque `Migrator` não existe.

- [ ] **Step 3: Implementar conexão e executor transacional de migrations**

```php
public function run(): array
{
    $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (migration VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    $applied = $this->pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $executed = [];
    foreach (glob($this->directory . '/*.sql') ?: [] as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) continue;
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec((string) file_get_contents($file));
            $stmt = $this->pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
            $stmt->execute(['migration' => $name]);
            $this->pdo->commit();
            $executed[] = $name;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    return $executed;
}
```

Create the MySQL schema with indexed `plans`, `users`, `password_reset_tokens`, `rate_limits`, `projects`, and `credit_transactions`; use InnoDB, utf8mb4, foreign keys, unique email, and indexes on ownership/status/date columns.

- [ ] **Step 4: Executar testes unitários e integração quando `TEST_DB_DSN` existir**

Run: `vendor/bin/phpunit tests/Unit/MigratorTest.php && php bin/migrate.php`

Expected: unit PASS; CLI prints each applied migration or `Nenhuma migration pendente`.

- [ ] **Step 5: Commit**

```bash
git add app/Core config database bin tests
git commit -m "feat: add database migrations"
```

### Task 3: HTTP, roteamento, sessão, CSRF e middleware

**Files:**
- Create: `app/Core/Request.php`
- Create: `app/Core/Response.php`
- Create: `app/Core/Router.php`
- Create: `app/Core/Session.php`
- Create: `app/Core/Csrf.php`
- Create: `app/Core/View.php`
- Create: `app/Middleware/AuthMiddleware.php`
- Create: `app/Middleware/GuestMiddleware.php`
- Create: `app/Middleware/CsrfMiddleware.php`
- Create: `app/Middleware/SecurityHeadersMiddleware.php`
- Create: `public/index.php`
- Create: `public/.htaccess`
- Create: `.htaccess`
- Test: `tests/Unit/RouterTest.php`
- Test: `tests/Unit/CsrfTest.php`

**Interfaces:**
- Produces: `Router::get(string $pattern, callable $handler, array $middleware = []): void`, `Router::post(...)`, `Router::dispatch(Request $request): Response`, `Csrf::token(): string`, `Csrf::validate(?string $token): bool`, `Response::redirect(string $url): self`.

- [ ] **Step 1: Escrever testes falhando para parâmetros de rota e rotação CSRF**

```php
public function testMatchesNamedRouteParameter(): void
{
    $router = new Router();
    $router->get('/projects/{id}', fn (Request $r, array $p) => Response::text($p['id']));
    self::assertSame('42', $router->dispatch(Request::fake('GET', '/projects/42'))->body());
}

public function testRejectsUnknownCsrfToken(): void
{
    self::assertFalse(Csrf::validate('invalid'));
}
```

- [ ] **Step 2: Confirmar falhas**

Run: `vendor/bin/phpunit tests/Unit/RouterTest.php tests/Unit/CsrfTest.php`

Expected: FAIL porque as classes HTTP ainda não existem.

- [ ] **Step 3: Implementar pipeline HTTP e regras Apache**

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

```php
public static function validate(?string $token): bool
{
    $stored = $_SESSION['_csrf'] ?? '';
    return is_string($token) && is_string($stored) && $stored !== '' && hash_equals($stored, $token);
}
```

The root `.htaccess` must return `403` for `/app`, `/bootstrap`, `/config`, `/database`, `/storage`, `/tests`, `/vendor`, `.env`, and Composer metadata when the domain document root cannot be pointed to `public`.

- [ ] **Step 4: Rodar testes HTTP unitários**

Run: `vendor/bin/phpunit tests/Unit/RouterTest.php tests/Unit/CsrfTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Core app/Middleware public .htaccess tests
git commit -m "feat: add secure http foundation"
```

### Task 4: Usuários, rate limiting, cadastro, login e logout

**Files:**
- Create: `app/Repositories/UserRepository.php`
- Create: `app/Repositories/PlanRepository.php`
- Create: `app/Services/RateLimiter.php`
- Create: `app/Services/AuthService.php`
- Create: `app/Controllers/AuthController.php`
- Create: `app/Validation/AuthValidator.php`
- Create: `routes/web.php`
- Create: `app/Views/auth/login.php`
- Create: `app/Views/auth/register.php`
- Test: `tests/Unit/AuthValidatorTest.php`
- Test: `tests/Unit/RateLimiterTest.php`
- Test: `tests/Integration/AuthServiceTest.php`

**Interfaces:**
- Produces: `AuthService::register(array $input): int`, `AuthService::attempt(string $email, string $password): bool`, `AuthService::logout(): void`, `RateLimiter::hit(string $action, string $subject, int $max, int $windowSeconds): bool`.

- [ ] **Step 1: Escrever testes falhando de validação e limite**

```php
public function testRejectsWeakRegistration(): void
{
    $errors = AuthValidator::registration(['name' => 'A', 'email' => 'x', 'password' => '123', 'password_confirmation' => '321']);
    self::assertArrayHasKey('name', $errors);
    self::assertArrayHasKey('email', $errors);
    self::assertArrayHasKey('password', $errors);
}

public function testBlocksAttemptAfterLimit(): void
{
    self::assertTrue($this->limiter->hit('login', 'person@example.com', 2, 60));
    self::assertTrue($this->limiter->hit('login', 'person@example.com', 2, 60));
    self::assertFalse($this->limiter->hit('login', 'person@example.com', 2, 60));
}
```

- [ ] **Step 2: Confirmar falhas**

Run: `vendor/bin/phpunit tests/Unit/AuthValidatorTest.php tests/Unit/RateLimiterTest.php`

Expected: FAIL porque validação e limitador não existem.

- [ ] **Step 3: Implementar autenticação transacional**

```php
public function attempt(string $email, string $password): bool
{
    $user = $this->users->findByEmail(mb_strtolower(trim($email)));
    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) return false;
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $this->users->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return true;
}
```

Registration must begin a transaction, create the Free-plan user with `password_hash`, add the initial credit transaction, commit, regenerate the session ID, and never return the password hash.

- [ ] **Step 4: Rodar testes e smoke test das rotas**

Run: `vendor/bin/phpunit tests/Unit/AuthValidatorTest.php tests/Unit/RateLimiterTest.php tests/Integration/AuthServiceTest.php`

Expected: PASS; integration is skipped with a clear reason when test MySQL is unavailable.

- [ ] **Step 5: Commit**

```bash
git add app/Repositories app/Services app/Controllers app/Validation app/Views/auth routes tests
git commit -m "feat: add secure authentication"
```

### Task 5: Recuperação e redefinição de senha

**Files:**
- Create: `app/Repositories/PasswordResetRepository.php`
- Create: `app/Contracts/Mailer.php`
- Create: `app/Services/NativeMailer.php`
- Create: `app/Services/LogMailer.php`
- Create: `app/Services/PasswordResetService.php`
- Create: `app/Controllers/PasswordResetController.php`
- Create: `app/Views/auth/forgot-password.php`
- Create: `app/Views/auth/reset-password.php`
- Test: `tests/Unit/PasswordResetServiceTest.php`

**Interfaces:**
- Consumes: `UserRepository` from Task 4.
- Produces: `PasswordResetService::request(string $email): void`, `PasswordResetService::reset(string $token, string $password): bool`, `Mailer::send(string $recipient, string $subject, string $html): void`.

- [ ] **Step 1: Escrever teste falhando garantindo token persistido como hash**

```php
public function testStoresOnlyTokenHash(): void
{
    $service = new PasswordResetService($this->users, $this->tokens, $this->mailer, 'https://example.test');
    $service->request('person@example.com');
    $plain = $this->mailer->capturedToken();
    self::assertNotSame($plain, $this->tokens->storedHash());
    self::assertSame(hash('sha256', $plain), $this->tokens->storedHash());
}
```

- [ ] **Step 2: Confirmar falha**

Run: `vendor/bin/phpunit tests/Unit/PasswordResetServiceTest.php`

Expected: FAIL porque o serviço não existe.

- [ ] **Step 3: Implementar token de uso único com expiração**

```php
$token = bin2hex(random_bytes(32));
$this->tokens->replaceForUser($userId, hash('sha256', $token), (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s'));
$url = $this->baseUrl . '/redefinir-senha?token=' . rawurlencode($token);
$this->mailer->send($email, 'Redefina sua senha', '<a href="' . e($url) . '">Redefinir senha</a>');
```

Reset must lock the matching row in a transaction, check expiration and `used_at`, update the password hash, mark the token used, revoke other tokens, and commit.

- [ ] **Step 4: Rodar testes do fluxo de senha**

Run: `vendor/bin/phpunit tests/Unit/PasswordResetServiceTest.php`

Expected: PASS for valid, expired, unknown, and already-used tokens.

- [ ] **Step 5: Commit**

```bash
git add app/Contracts app/Repositories app/Services app/Controllers app/Views/auth tests
git commit -m "feat: add password recovery"
```

### Task 6: Design system e landing page

**Files:**
- Create: `app/Controllers/HomeController.php`
- Create: `app/Views/layouts/marketing.php`
- Create: `app/Views/home.php`
- Create: `app/Views/components/logo.php`
- Create: `public/assets/css/app.css`
- Create: `public/assets/js/app.js`
- Create: `public/assets/images/product-preview.svg`
- Test: `tests/Feature/HomePageTest.php`

**Interfaces:**
- Consumes: `View`, `Router`, `e()` from Tasks 1 and 3.
- Produces: public route `GET /`, reusable CSS tokens and shared logo component.

- [ ] **Step 1: Escrever teste falhando da landing**

```php
public function testLandingContainsPrimaryJourney(): void
{
    $response = $this->get('/');
    self::assertSame(200, $response->status());
    self::assertStringContainsString('Transforme vídeos longos', $response->body());
    self::assertStringContainsString('/cadastro', $response->body());
    self::assertStringContainsString('Como funciona', $response->body());
}
```

- [ ] **Step 2: Confirmar falha**

Run: `vendor/bin/phpunit tests/Feature/HomePageTest.php`

Expected: FAIL porque a rota e view não existem.

- [ ] **Step 3: Implementar landing responsiva e identidade própria**

```css
:root {
  --bg: #080808; --surface: #101010; --card: #151515; --card-hover: #1c1c1c;
  --border: #262626; --text: #fff; --muted: #a1a1aa; --subtle: #71717a;
  --primary: #7c3aed; --secondary: #a855f7; --success: #22c55e;
  --radius: 18px; --shadow: 0 24px 70px rgba(0, 0, 0, .35);
}
```

The page must contain navigation, hero, product preview, three-step flow, feature grid, formats, final CTA and footer. Motion must respect `prefers-reduced-motion`; all controls need visible keyboard focus.

- [ ] **Step 4: Rodar teste e validar markup**

Run: `vendor/bin/phpunit tests/Feature/HomePageTest.php && php -l app/Views/home.php`

Expected: PASS and no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add app/Controllers app/Views public/assets routes tests
git commit -m "feat: build premium landing page"
```

### Task 7: Dashboard, projeto real e perfil

**Files:**
- Create: `app/Repositories/ProjectRepository.php`
- Create: `app/Repositories/CreditTransactionRepository.php`
- Create: `app/Services/DashboardService.php`
- Create: `app/Controllers/DashboardController.php`
- Create: `app/Controllers/ProfileController.php`
- Create: `app/Views/layouts/app.php`
- Create: `app/Views/dashboard/index.php`
- Create: `app/Views/profile/edit.php`
- Create: `public/assets/js/dashboard.js`
- Test: `tests/Unit/DashboardServiceTest.php`
- Test: `tests/Feature/DashboardAccessTest.php`

**Interfaces:**
- Produces: `DashboardService::forUser(int $userId): array{projects:int,processed:int,minutes:int,credits:int,storage_bytes:int,recent:array}`, private routes `GET /dashboard`, `GET|POST /perfil`.

- [ ] **Step 1: Escrever testes falhando de isolamento e autenticação**

```php
public function testGuestIsRedirectedFromDashboard(): void
{
    self::assertSame('/login', $this->get('/dashboard')->header('Location'));
}

public function testMetricsReceiveAuthenticatedUserId(): void
{
    $service = new DashboardService($this->projects, $this->credits);
    $service->forUser(17);
    self::assertSame(17, $this->projects->lastRequestedUserId());
}
```

- [ ] **Step 2: Confirmar falhas**

Run: `vendor/bin/phpunit tests/Unit/DashboardServiceTest.php tests/Feature/DashboardAccessTest.php`

Expected: FAIL porque serviço e rota não existem.

- [ ] **Step 3: Implementar queries com escopo de proprietário e interface**

```sql
SELECT id, title, status, progress, duration, created_at
FROM projects
WHERE user_id = :user_id
ORDER BY created_at DESC
LIMIT 6
```

Dashboard must show the five real metrics, recent projects or an honest empty state, plan badge, credit balance, and disabled/future-ready `Criar novo projeto` CTA linked to `/projetos/novo`. The mobile sidebar becomes an accessible drawer with focus management.

- [ ] **Step 4: Rodar testes e lint das views**

Run: `vendor/bin/phpunit tests/Unit/DashboardServiceTest.php tests/Feature/DashboardAccessTest.php && php -l app/Views/dashboard/index.php && php -l app/Views/layouts/app.php`

Expected: PASS and no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add app/Repositories app/Services app/Controllers app/Views public/assets/js routes tests
git commit -m "feat: add authenticated dashboard"
```

### Task 8: Logs, erros, instalação Hostinger e verificação final

**Files:**
- Create: `app/Core/Logger.php`
- Create: `app/Core/ErrorHandler.php`
- Create: `app/Views/errors/404.php`
- Create: `app/Views/errors/419.php`
- Create: `app/Views/errors/429.php`
- Create: `app/Views/errors/500.php`
- Create: `storage/logs/.gitignore`
- Create: `storage/cache/.gitignore`
- Create: `README.md`
- Create: `docs/HOSTINGER.md`
- Create: `bin/check-requirements.php`
- Test: `tests/Unit/LoggerTest.php`
- Test: `tests/Feature/ErrorPageTest.php`

**Interfaces:**
- Produces: `Logger::error(string $message, array $context = []): void`, `ErrorHandler::register(bool $debug): void`, CLI environment checker.

- [ ] **Step 1: Escrever teste falhando de sanitização de logs**

```php
public function testRedactsSensitiveContext(): void
{
    $logger = new Logger($this->logFile);
    $logger->error('request failed', ['password' => 'secret', 'token' => 'abc', 'user_id' => 9]);
    $contents = file_get_contents($this->logFile);
    self::assertStringNotContainsString('secret', $contents);
    self::assertStringNotContainsString('abc', $contents);
    self::assertStringContainsString('"user_id":9', $contents);
}
```

- [ ] **Step 2: Confirmar falha**

Run: `vendor/bin/phpunit tests/Unit/LoggerTest.php tests/Feature/ErrorPageTest.php`

Expected: FAIL porque logger, handler e páginas ainda não existem.

- [ ] **Step 3: Implementar redaction, páginas seguras e guia de deploy**

```php
private function sanitize(array $context): array
{
    $blocked = ['password', 'password_confirmation', 'token', 'authorization', 'api_key', 'gemini_api_key'];
    foreach ($context as $key => $value) {
        $context[$key] = in_array(strtolower((string) $key), $blocked, true)
            ? '[REDACTED]'
            : (is_array($value) ? $this->sanitize($value) : $value);
    }
    return $context;
}
```

`docs/HOSTINGER.md` must specify PHP 8.1+, required extensions, MySQL database/user creation, `.env` values, `composer install --no-dev --optimize-autoloader`, document-root preference for `public`, fallback root `.htaccess`, `php bin/migrate.php`, HTTPS, session directory permissions, mail configuration, and a rollback backup procedure.

- [ ] **Step 4: Executar verificação completa**

Run: `vendor/bin/phpunit && Get-ChildItem app,bootstrap,bin,config,public,routes,tests -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName } && php bin/check-requirements.php`

Expected: all tests PASS (MySQL integrations may be explicitly skipped without `TEST_DB_DSN`), all PHP files report no syntax errors, requirements checker reports PHP/PDO/mbstring present.

- [ ] **Step 5: Verificar segurança e árvore de trabalho**

Run: `rg -n "GEMINI_API_KEY=.+|password\s*=\s*['\"][^'\"]+|display_errors\s*=\s*1" -g '!vendor/**' . && git status --short`

Expected: secret scan prints no matches; Git shows only intended delivery changes.

- [ ] **Step 6: Commit**

```bash
git add app/Core app/Views/errors storage README.md docs/HOSTINGER.md bin tests
git commit -m "docs: add hostinger deployment and hardening"
```

## Subsequent project plans

After this plan is verified, create separate design/implementation cycles for these independently testable subsystems:

1. project upload, URL registration, protected media delivery, library and status endpoint;
2. MySQL processing queue, cron runner, retries, progress and FFmpeg metadata;
3. Gemini upload/analysis, structured output validation and clip suggestions;
4. FFmpeg clip rendering, thumbnails and protected downloads;
5. MediaPipe browser preview, face tracks and Smart Reframe keyframes;
6. subtitles, styles and burn-in rendering;
7. simplified editor with player, timeline and render settings;
8. credit enforcement, plans and usage ledger;
9. administration, Gemini configuration test, system logs and monitoring.

The final acceptance criterion for the full program is an uploaded video producing persisted, downloadable rendered clips through real queued processing. On Hostinger plans where `exec` or FFmpeg is unavailable, the same `VideoProcessor` contract will call an external VPS processor while PHP/MySQL remain the control plane.

