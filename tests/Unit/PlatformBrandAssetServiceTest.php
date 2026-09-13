<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Services\PlatformBrandAssetService;use PHPUnit\Framework\TestCase;
final class PlatformBrandAssetServiceTest extends TestCase {public function testStoresValidatedPngWithRandomPublicHashPath():void{$dir=sys_get_temp_dir().'/brand-'.bin2hex(random_bytes(5));mkdir($dir);$tmp=dirname(__DIR__).'/Fixtures/mediapipe-synthetic-face.png';$service=new PlatformBrandAssetService($dir,static fn(string $path):bool=>is_file($path));$url=$service->store('logo',['error'=>UPLOAD_ERR_OK,'tmp_name'=>$tmp]);self::assertMatchesRegularExpression('#^/assets/images/logo-[a-f0-9]{48}\.png$#',$url);self::assertFileExists($dir.'/'.basename($url));array_map('unlink',glob($dir.'/*')?:[]);rmdir($dir);} }
