<?php
declare(strict_types=1);
namespace App\Communications;

/** Closed email markup vocabulary. No CSS, remote images, scripts or active content. */
final class EmailHtmlPolicy
{
    public function validate(string $html): void
    {
        if (strlen($html)>200000 || !mb_check_encoding($html,'UTF-8')) throw new \InvalidArgumentException('HTML inválido ou muito grande.');
        $previous=libxml_use_internal_errors(true);
        try {
            $document=new \DOMDocument('1.0','UTF-8');
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
            foreach ($document->getElementsByTagName('*') as $node) {
                $tag=strtolower($node->tagName);
                if (!in_array($tag,['html','body','p','br','strong','b','em','i','u','h1','h2','h3','h4','ul','ol','li','blockquote','a','table','thead','tbody','tr','td','th','hr','span','div'],true)) throw new \InvalidArgumentException('HTML contém elemento não permitido: '.$tag.'.');
                foreach ($node->attributes as $attribute) {
                    $name=strtolower($attribute->name);
                    if ($tag==='a' && $name==='href') { $this->validateUrl($attribute->value,true); continue; }
                    if ($name==='title' && !str_contains($attribute->value,'{{')) continue;
                    if (in_array($tag,['td','th'],true) && in_array($name,['colspan','rowspan'],true) && preg_match('/^[1-9][0-9]?$/D',$attribute->value)) continue;
                    throw new \InvalidArgumentException('HTML contém atributo não permitido: '.$name.'.');
                }
            }
        } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    public function validateUrl(string $url,bool $allowPlaceholder=false):void
    {
        if ($allowPlaceholder && preg_match('/^\{\{link_[a-z_]+\}\}$/D',$url)) return;
        if (preg_match('/[\x00-\x20\x7f\\\\]/',$url)) throw new \InvalidArgumentException('Link de e-mail inválido.');
        $p=parse_url($url);$scheme=is_array($p)?strtolower((string)($p['scheme']??'')):'';$host=is_array($p)?strtolower((string)($p['host']??'')):'';
        if ($host==='' || isset($p['user']) || isset($p['pass']) || ($scheme!=='https' && !($scheme==='http' && in_array($host,['localhost','127.0.0.1'],true)))) throw new \InvalidArgumentException('Use um link HTTPS válido.');
    }
}
