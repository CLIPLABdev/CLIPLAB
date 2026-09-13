<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ProfileController;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;
use App\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProfileControllerTest extends TestCase
{
    public function testUpdatesOnlyTheCurrentUsersValidProfileFields(): void
    {
        $_SESSION = ['user_id' => 1];
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, plan_id INTEGER NOT NULL, credits INTEGER NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL)');
        $pdo->exec("INSERT INTO users (id, name, email, password_hash, plan_id, credits, role, status) VALUES (1, 'Ana', 'ana@example.test', 'hash', 1, 10, 'user', 'active'), (2, 'Outra', 'outra@example.test', 'hash', 1, 55, 'admin', 'active')");
        $controller = new ProfileController(new View(), new UserRepository($pdo));

        $response = $controller->update(Request::fake('POST', '/perfil', [
            '_token' => Csrf::token(),
            'name' => 'Ana Atualizada',
            'email' => 'ANA@example.test',
            'role' => 'admin',
            'credits' => '9999',
            'plan_id' => '2',
            'status' => 'suspended',
        ]));

        $record = $pdo->query('SELECT name, email, plan_id, credits, role, status FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(302, $response->status());
        self::assertSame('/perfil', $response->header('Location'));
        self::assertSame('Ana Atualizada', $record['name']);
        self::assertSame('ana@example.test', $record['email']);
        self::assertSame(1, (int) $record['plan_id']);
        self::assertSame(10, (int) $record['credits']);
        self::assertSame('user', $record['role']);
        self::assertSame('active', $record['status']);
    }

    public function testRejectsAnEmailOwnedByAnotherUser(): void
    {
        $_SESSION = ['user_id' => 1];
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, plan_id INTEGER NOT NULL, credits INTEGER NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL)');
        $pdo->exec("INSERT INTO users (id, name, email, password_hash, plan_id, credits, role, status) VALUES (1, 'Ana', 'ana@example.test', 'hash', 1, 10, 'user', 'active'), (2, 'Outra', 'outra@example.test', 'hash', 1, 55, 'admin', 'active')");
        $controller = new ProfileController(new View(), new UserRepository($pdo));

        $response = $controller->update(Request::fake('POST', '/perfil', [
            '_token' => Csrf::token(),
            'name' => 'Ana',
            'email' => 'outra@example.test',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/perfil', $response->header('Location'));
        self::assertSame('ana@example.test', $pdo->query('SELECT email FROM users WHERE id = 1')->fetchColumn());
        self::assertSame('Este e-mail já está em uso.', $_SESSION['_flash_profile_errors']['email']);
    }

    public function testEmailChangeCannotBypassConfirmationWhenServiceIsUnavailable(): void
    {
        $_SESSION = ['user_id'=>1];
        $pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT)');
        $pdo->exec("INSERT INTO users VALUES(1,'Ana','ana@example.test')");
        $controller = new ProfileController(new View(),new UserRepository($pdo));
        $controller->update(Request::fake('POST','/perfil',['name'=>'Ana','email'=>'new@example.test','current_password'=>'must-not-be-flashed']));
        self::assertSame('ana@example.test',$pdo->query('SELECT email FROM users WHERE id=1')->fetchColumn());
        self::assertStringNotContainsString('must-not-be-flashed',json_encode($_SESSION));
        self::assertNotEmpty($_SESSION['_flash_profile_errors']);
    }

    public function testArrayInputsAreRejectedWithoutWarnings(): void
    {
        $_SESSION = ['user_id'=>1];
        $pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $controller = new ProfileController(new View(),new UserRepository($pdo));
        $response = $controller->update(Request::fake('POST','/perfil',['name'=>[],'email'=>[]]));
        self::assertSame(302,$response->status());
        self::assertNotEmpty($_SESSION['_flash_profile_errors']);
    }
}
