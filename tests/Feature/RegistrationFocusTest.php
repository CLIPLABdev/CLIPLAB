<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class RegistrationFocusTest extends TestCase
{
    public function testOnlyFirstInvalidRegistrationFieldReceivesFocus(): void
    {
        $_SESSION = [];
        foreach ([['name'=>'Nome inválido.','password'=>'Senha inválida.'], ['email'=>'E-mail inválido.','password'=>'Senha inválida.']] as $errors) {
            $old=[];
            $message=null;
            ob_start();
            require dirname(__DIR__,2).'/app/Views/auth/register.php';
            $html=(string)ob_get_clean();
            self::assertSame(1,substr_count($html,' autofocus'));
        }
        $_SESSION = [];
    }
}
