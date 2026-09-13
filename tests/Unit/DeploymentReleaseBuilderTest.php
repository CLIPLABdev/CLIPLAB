<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Deployment\ReleaseBuilder;
use PHPUnit\Framework\TestCase;
use ZipArchive;
final class DeploymentReleaseBuilderTest extends TestCase
{
 private string $root;
 protected function setUp(): void { $this->root=sys_get_temp_dir().'/release-fixture-'.bin2hex(random_bytes(6)); mkdir($this->root.'/app',0700,true); mkdir($this->root.'/public',0700,true); mkdir($this->root.'/vendor',0700,true); mkdir($this->root.'/storage',0700,true); file_put_contents($this->root.'/app/a.php','<?php'); file_put_contents($this->root.'/public/.htaccess','deny'); file_put_contents($this->root.'/vendor/autoload.php','<?php'); file_put_contents($this->root.'/composer.lock','{}'); file_put_contents($this->root.'/.htaccess','root'); file_put_contents($this->root.'/.env','SECRET=canary'); file_put_contents($this->root.'/storage/media.mp4','private'); }
 protected function tearDown(): void { if(!isset($this->root)||!is_dir($this->root))return; $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());} rmdir($this->root); }
 public function testBuildsAllowlistedArchiveWithVerifiedManifestAndNoSecrets(): void { $output=sys_get_temp_dir().'/clipforge-'.bin2hex(random_bytes(6)).'.zip'; try{(new ReleaseBuilder($this->root))->build($output); $zip=new ZipArchive(); self::assertTrue($zip->open($output)); self::assertNotFalse($zip->locateName('app/a.php')); self::assertNotFalse($zip->locateName('public/.htaccess')); self::assertNotFalse($zip->locateName('.htaccess')); self::assertNotFalse($zip->locateName('vendor/autoload.php')); self::assertFalse($zip->locateName('.env')); self::assertFalse($zip->locateName('storage/media.mp4')); $manifest=json_decode((string)$zip->getFromName('release-manifest.json'),true,512,JSON_THROW_ON_ERROR); self::assertSame(hash('sha256','<?php'),$manifest['files']['app/a.php']); $zip->close();}finally{@unlink($output);} }
 public function testRejectsExistingOutput(): void { $builder=new ReleaseBuilder($this->root); $existing=tempnam(sys_get_temp_dir(),'release-existing-'); try{$this->expectException(\InvalidArgumentException::class);$builder->build($existing);}finally{@unlink($existing);} }
}
