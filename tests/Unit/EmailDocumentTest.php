<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Communications\{EmailDocument, EmailTemplateRenderer, CommunicationTemplateService, CommunicationEventCatalog};
use PDO;
use PHPUnit\Framework\TestCase;

final class EmailDocumentTest extends TestCase
{
    public function testDocumentKeepsEscapedContentAndSafeLinkInBothPresentations(): void
    {
        $fragment=(new EmailTemplateRenderer())->render('<h1>Olá, {{nome_usuario}}</h1><p><a href="{{link_recuperacao}}">Redefinir senha</a></p>', ['nome_usuario'=>'<Ana & Bia>','link_recuperacao'=>'https://example.test/reset?a=1&b=2'], ['nome_usuario','link_recuperacao']);
        $document=new EmailDocument();
        foreach ([false,true] as $preview) {
            $html=$document->render('Conta <segura>', $fragment, $preview);
            $dom=new \DOMDocument();@$dom->loadHTML($html);
            self::assertSame('Conta <segura>', $dom->getElementsByTagName('title')->item(0)->textContent);
            self::assertSame('Olá, <Ana & Bia>', $dom->getElementsByTagName('h1')->item(0)->textContent);
            self::assertSame('https://example.test/reset?a=1&b=2', $dom->getElementsByTagName('a')->item(0)->getAttribute('href'));
            self::assertSame(0,$dom->getElementsByTagName('script')->length);
            self::assertSame(0,$dom->getElementsByTagName('img')->length);
            self::assertSame(1,$dom->getElementsByTagName('body')->length);
            $xpath=new \DOMXPath($dom);
            if ($preview) self::assertSame(0,$xpath->query('//*[@style]')->length);
            else {
                self::assertGreaterThan(0,$xpath->query('//*[@style]')->length);
                $css=file_get_contents(dirname(__DIR__,2).'/public/assets/css/email-document.css');
                foreach($xpath->query('//*[@style]') as $node) self::assertStringContainsString('.'.$node->getAttribute('class').'{'.$node->getAttribute('style').'}',$css);
            }
        }
    }

    /** @dataProvider unsafeHtml */
    public function testDocumentCannotBypassHtmlPolicy(string $html): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EmailDocument())->render('Teste',$html);
    }
    public function unsafeHtml(): array {return [['<p style="color:red">X</p>'],['<script>alert(1)</script>'],['<a href="javascript:alert(1)">X</a>'],['<img src="https://example.test/x">']];}

    public function testSystemCopiesAreValidForEveryEventAndPlainTextIncludesEveryActionUrl(): void
    {
        $service=new CommunicationTemplateService(new PDO('sqlite::memory:'));
        foreach((new CommunicationEventCatalog())->events() as $event=>$definition) {
            $copy=$service->systemTemplate($event);
            $variables=$service->exampleVariables($event);
            $renderer=new EmailTemplateRenderer();
            $subject=$renderer->renderSubject($copy['subject_template'],$variables,$definition['variables']);
            $html=$renderer->render($copy['html_template'],$variables,$definition['variables']);
            $text=$renderer->renderText($copy['text_template'],$variables,$definition['variables']);
            self::assertNotSame('',$subject);self::assertNotSame('',$html);self::assertNotSame('',$text);
            preg_match_all('/href="(\{\{link_[a-z_]+\}\})"/',$copy['html_template'],$matches);
            foreach($matches[1] as $token) self::assertStringContainsString($token,$copy['text_template']);
        }
    }
}
