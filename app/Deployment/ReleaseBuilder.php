<?php

declare(strict_types=1);

namespace App\Deployment;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final class ReleaseBuilder
{
    private const DIRECTORIES=['app','bootstrap','config','routes','public','database/migrations','database/seeds','bin','vendor'];
    private const ROOT_FILES=['.htaccess','index.php','composer.json','composer.lock','LICENSE','LICENSE.md','LICENSE.txt','COPYING',
        '.env.example','README.md','docs/HOSTINGER.md','docs/DELIVERY.md','docs/HOMOLOGACAO_VIDEO_OFICIAL.md',
        'docs/PLATAFORMA_OPERACAO.md','docs/RELATORIO_APRIMORAMENTO_2026-09-07.md','tools/activate-platform.php'];
    private const EXCLUDED_DIRECTORIES=['test','tests','__tests__','fixture','fixtures','__fixtures__','node_modules','coverage','examples','example','docs','documentation','backup','backups'];
    private const DATA_DIRECTORIES=['storage','cache','caches','logs','log','uploads','upload','tmp','temp'];
    private const CODE_DATA_PREFIXES=['app/Storage','vendor/Psr/Log','vendor/psr/log'];
    private const PUBLIC_EXTENSIONS=['php','html','css','js','mjs','json','svg','png','jpg','jpeg','webp','ico','woff','woff2','ttf','otf','wasm','tflite','map','txt','webmanifest'];
    private const VENDOR_EXTENSIONS=['php','inc','json','md','txt','xml','xsd','dtd','neon','yml','yaml','dist','ini','css','js','mjs','svg','png','ico','wasm','tflite','stub'];

    public function __construct(private string $sourceRoot)
    {
        $root=realpath($sourceRoot);
        if ($root===false || !is_dir($root)) throw new InvalidArgumentException('Release source is invalid.');
        $this->sourceRoot=$root;
    }

    /** @return array{output:string,files:int,manifest_sha256:string} */
    public function build(string $output): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('ZIP extension is required.');
        $target=$this->outputPath($output);
        $files=$this->collect();
        $zip=new ZipArchive();
        if ($zip->open($target,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Release archive could not be created.');
        $manifest=['format'=>1,'files'=>[]];
        try {
            foreach ($files as $relative=>$absolute) {
                if (!$this->safeSourcePath($absolute) || !is_file($absolute)) throw new RuntimeException('Release source changed while building.');
                $contents=file_get_contents($absolute);
                if (!is_string($contents) || !$this->safeSourcePath($absolute)) throw new RuntimeException('Release file could not be read.');
                // Add the exact bytes hashed below; ZipArchive::addFile reads lazily at close().
                if (!$zip->addFromString($relative,$contents)) throw new RuntimeException('Release file could not be added.');
                $manifest['files'][$relative]=hash('sha256',$contents);
                unset($contents);
            }
            $json=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if (!$zip->addFromString('release-manifest.json',$json) || !$zip->close()) throw new RuntimeException('Release archive could not be finalized.');
        } catch (\Throwable $error) {
            try { $zip->close(); } catch (\Throwable) {}
            if (is_file($target) && !is_link($target)) @unlink($target);
            throw $error;
        }
        return ['output'=>$target,'files'=>count($files),'manifest_sha256'=>hash('sha256',$json)];
    }

    private function outputPath(string $output): string
    {
        $normalized=str_replace('\\','/',$output);
        if ($output==='' || str_contains($output,"\0") || preg_match('#(?:^|/)\.\.?(/|$)#',$normalized)
            || preg_match('#(?:^|/)(?:public|public_html|storage)(?:/|$)#i',$normalized)
            || preg_match('/^[^<>:"|?*]+\.zip$/i',basename($normalized))!==1 || file_exists($output) || is_link($output)) {
            throw new InvalidArgumentException('Release output must be a new ZIP outside public and storage.');
        }
        $parent=realpath(dirname($output));
        if ($parent===false || !is_dir($parent) || !is_writable($parent)) throw new InvalidArgumentException('Release output directory is invalid.');
        $target=$parent.DIRECTORY_SEPARATOR.basename($output);
        if ($this->insideSource($target) || preg_match('#(?:^|/)(?:public|public_html|storage)(?:/|$)#i',str_replace('\\','/',$parent))) {
            throw new InvalidArgumentException('Release output must be outside the source, public and storage.');
        }
        return $target;
    }

    /** @return array<string,string> */
    private function collect(): array
    {
        $found=[];
        foreach (self::DIRECTORIES as $directory) $this->scan($directory,$found);
        foreach (self::ROOT_FILES as $file) {
            $path=$this->sourceRoot.DIRECTORY_SEPARATOR.$file;
            if (is_file($path) && $this->safeSourcePath($path)) $found[$file]=$path;
        }
        ksort($found,SORT_STRING);
        return $found;
    }

    private function scan(string $relative,array &$found): void
    {
        $directory=$this->sourceRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        if (!is_dir($directory) || !$this->safeSourcePath($directory)) return;
        $entries=scandir($directory);
        if ($entries===false) throw new RuntimeException('Release directory could not be read.');
        foreach ($entries as $name) {
            if ($name==='.' || $name==='..') continue;
            $child=$relative.'/'.$name;
            $absolute=$directory.DIRECTORY_SEPARATOR.$name;
            if (!$this->safeSourcePath($absolute)) continue;
            if (is_dir($absolute)) {
                if ($name[0]!=='.' && !$this->excludedDirectory($child)) $this->scan($child,$found);
            } elseif (is_file($absolute) && $this->allowedFile($child)) $found[$child]=$absolute;
        }
    }

    private function excludedDirectory(string $relative): bool
    {
        $segments=explode('/',$relative);
        $name=strtolower((string)end($segments));
        if (in_array($name,self::EXCLUDED_DIRECTORIES,true)) return true;
        if (!in_array($name,self::DATA_DIRECTORIES,true)) return false;
        foreach (self::CODE_DATA_PREFIXES as $prefix) {
            if ($relative===$prefix || str_starts_with($relative,$prefix.'/')) return false;
        }
        return true;
    }

    private function allowedFile(string $relative): bool
    {
        if ($relative==='public/.htaccess') return true;
        $name=basename($relative);
        $lower=strtolower($name);
        if ($name[0]==='.' || in_array($lower,['auth.json','credentials.json','secrets.json','phpunit.xml','phpunit.xml.dist','phpstan.neon','psalm.xml'],true)
            || preg_match('/(?:\.local\.|^local\.|\.secret\.|\.secrets\.)/i',$name)
            || preg_match('/(?:~|\.(?:bak|backup|old|orig|swp|log|key|pem|p12|pfx|sql\.gz))$/i',$name)) return false;
        if (preg_match('/^(?:licen[cs]e|copying|notice|copyright)(?:\.[a-z0-9_-]+)?$/i',$name)) return true;
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if (str_starts_with($relative,'public/')) return in_array($extension,self::PUBLIC_EXTENSIONS,true);
        if (str_starts_with($relative,'vendor/')) return in_array($extension,self::VENDOR_EXTENSIONS,true);
        if (str_starts_with($relative,'database/')) return $extension==='sql' || $extension==='php';
        return $extension==='php';
    }

    private function safeSourcePath(string $path): bool
    {
        if (is_link($path)) return false;
        $resolved=realpath($path);
        // Also rejects Windows junctions, which PHP may not report as symbolic links.
        return $resolved!==false && $this->insideSource($resolved) && $this->canonical($resolved)===$this->canonical($path);
    }
    private function insideSource(string $path): bool { return str_starts_with($this->canonical($path),$this->canonical($this->sourceRoot).'/'); }
    private function canonical(string $path): string
    {
        $path=rtrim(str_replace('\\','/',$path),'/');
        return DIRECTORY_SEPARATOR==='\\' ? strtolower($path) : $path;
    }
}
