<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Communications\EmailTemplateRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmailTemplateRendererTest extends TestCase
{
    public function testEscapesVariablesBeforePlacingThemInHtml(): void
    {
        $renderer = new EmailTemplateRenderer();

        $html = $renderer->render('<p>Olá, {{nome_usuario}}</p><a href="{{link_recuperacao}}">Continuar</a>', [
            'nome_usuario' => '<script>alert(1)</script>',
            'link_recuperacao' => 'https://app.example.test/redefinir?token=abc',
        ], ['nome_usuario', 'link_recuperacao']);

        self::assertSame('<p>Olá, &lt;script&gt;alert(1)&lt;/script&gt;</p><a href="https://app.example.test/redefinir?token=abc">Continuar</a>', $html);
    }

    public function testRejectsUnapprovedTemplateVariablesAndUnsafeLinks(): void
    {
        $renderer = new EmailTemplateRenderer();

        $this->expectException(InvalidArgumentException::class);
        $renderer->render('<a href="{{link_recuperacao}}">Continuar</a>{{segredo}}', [
            'link_recuperacao' => 'javascript:alert(1)',
            'segredo' => 'nope',
        ], ['link_recuperacao']);
    }
}
