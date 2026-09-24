<?php
declare(strict_types=1);
namespace App\Communications;

/** Presentation is trusted system code, applied only after the closed HTML policy. */
final class EmailDocument
{
    private const STYLES = [
        'page'=>'margin:0;padding:0;background:#F7F7F7;color:#000000;font-family:Helvetica Neue,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.65;word-break:break-word;',
        'outer'=>'width:100%;border-collapse:collapse;',
        'gutter'=>'padding:28px 12px;',
        'card'=>'width:100%;max-width:600px;margin:0 auto;border-collapse:collapse;background:#ffffff;',
        'brand'=>'padding:26px 28px;background:#000000;color:#17F0C5;font-size:23px;font-weight:500;letter-spacing:-1px;border-bottom:4px solid #17F0C5;',
        'content'=>'padding:28px;',
        'footer'=>'padding:20px 28px;background:#F7F7F7;color:#252525;font-size:12px;line-height:1.6;border-top:1px solid #E5E5E5;',
        'p'=>'margin:0 0 20px;',
        'h1'=>'margin:0 0 24px;font-size:30px;line-height:1.2;letter-spacing:-1px;color:#000000;',
        'h2'=>'margin:24px 0 16px;font-size:24px;line-height:1.3;color:#000000;',
        'h3'=>'margin:20px 0 12px;font-size:20px;line-height:1.4;color:#000000;',
        'h4'=>'margin:20px 0 12px;font-size:18px;line-height:1.4;color:#000000;',
        'a'=>'display:inline-block;padding:12px 18px;background:#17F0C5;color:#000000;border:1px solid #0BC9A5;border-radius:8px;font-weight:500;text-decoration:underline;line-height:1.5;max-width:100%;box-sizing:border-box;',
        'ul'=>'margin:0 0 20px;padding-left:24px;',
        'ol'=>'margin:0 0 20px;padding-left:24px;',
        'li'=>'margin:0 0 8px;',
        'blockquote'=>'margin:20px 0;padding:16px 20px;background:#F7F7F7;border-left:3px solid #0BC9A5;',
        'table'=>'width:100%;border-collapse:collapse;margin:0 0 20px;table-layout:fixed;',
        'td'=>'padding:12px 8px;border-bottom:1px solid #E5E5E5;vertical-align:top;',
        'th'=>'padding:12px 8px;border-bottom:1px solid #E5E5E5;text-align:left;background:#F7F7F7;',
        'hr'=>'border:0;border-top:1px solid #E5E5E5;margin:24px 0;',
    ];

    public function render(string $subject, string $fragment, bool $preview=false): string
    {
        (new EmailHtmlPolicy())->validate($fragment);
        $previous=libxml_use_internal_errors(true);
        try {
            $dom=new \DOMDocument('1.0','UTF-8');
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$fragment.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
            foreach($dom->getElementsByTagName('*') as $node) {
                $tag=strtolower($node->tagName);
                if(isset(self::STYLES[$tag])) {
                    $node->setAttribute('class','cf-email-'.$tag);
                    if(!$preview)$node->setAttribute('style',self::STYLES[$tag]);
                }
            }
            $content='';foreach($dom->getElementsByTagName('body')->item(0)->childNodes as $node)$content.=$dom->saveHTML($node);
        } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
        $attr=static fn(string $name):string=>'class="cf-email-'.$name.'"'.($preview?'':' style="'.self::STYLES[$name].'"');
        $title=htmlspecialchars($subject,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$title.'</title>'.($preview?'<link rel="stylesheet" href="/assets/css/email-document.css">':'').'</head><body '.$attr('page').'><table role="presentation" '.$attr('outer').'><tr><td '.$attr('gutter').'><table role="presentation" '.$attr('card').'><tr><td '.$attr('brand').'>ClipForge<span aria-hidden="true"> /</span></td></tr><tr><td '.$attr('content').'>'.$content.'</td></tr><tr><td '.$attr('footer').'><strong>ClipForge</strong><br>Seu espaço para criar. Sua conta sempre por perto.<br>Proteja seu acesso: nunca compartilhe senhas ou links de confirmação.</td></tr></table></td></tr></table></body></html>';
    }
}
