<?php
declare(strict_types=1);
namespace App\Services;
use App\Media\Editor\BrandLogoPng;
final class PlatformBrandAssetService {
 private \Closure $isUpload;
 public function __construct(private string $directory,?callable $isUpload=null){$this->directory=rtrim($directory,'/\\');$this->isUpload=$isUpload===null?static fn(string $p):bool=>is_uploaded_file($p):\Closure::fromCallable($isUpload);}
 /** @param array<string,mixed>|null $upload */ public function store(string $kind,?array $upload):?string {if($upload===null||($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;if(!in_array($kind,['logo','favicon'],true)||($upload['error']??null)!==UPLOAD_ERR_OK||!is_string($upload['tmp_name']??null)||!(($this->isUpload)($upload['tmp_name'])))throw new \InvalidArgumentException('Envie um PNG válido.');$bytes=file_get_contents($upload['tmp_name'],false,null,0,BrandLogoPng::MAX_BYTES+1);if(!is_string($bytes))throw new \InvalidArgumentException('Envie um PNG válido.');BrandLogoPng::dimensions($bytes);if(!is_dir($this->directory)&&!mkdir($this->directory,0755,true)&&!is_dir($this->directory))throw new \RuntimeException('Armazenamento indisponível.');$root=realpath($this->directory);if($root===false)throw new \RuntimeException('Armazenamento indisponível.');$name=$kind.'-'.bin2hex(random_bytes(24)).'.png';$path=$root.DIRECTORY_SEPARATOR.$name;$h=fopen($path,'xb');if($h===false)throw new \RuntimeException('Armazenamento indisponível.');try{$written=fwrite($h,$bytes);}finally{fclose($h);}if($written!==strlen($bytes)){@unlink($path);throw new \RuntimeException('Armazenamento indisponível.');}return '/assets/images/'.$name; }
}
