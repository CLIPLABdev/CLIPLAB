<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Deployment\ReleaseBuilder;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class DeploymentReleaseHardeningTest extends TestCase
{
    private string $temporary;
    private string $root;
    protected function setUp(): void
    {
        $this->temporary=sys_get_temp_dir().'/release-hardening-'.bin2hex(random_bytes(8));
        $this->root=$this->temporary.'/source';
        mkdir($this->root,0700,true);
        foreach (['app/App.php','public/index.php','public/.htaccess','vendor/autoload.php','vendor/composer/autoload_real.php',
            'vendor/vendor-name/package/src/Runtime.php','vendor/vendor-name/package/LICENSE',
            'vendor/Psr/Log/LoggerInterface.php',
            'app/Storage/LocalPrivateStorage.php','app/Storage/PrivateStagingArea.php','app/Storage/PrivateStagingFile.php',
            'app/Media/OverlaySafeZone.php','app/Views/clips/editor.php','public/assets/js/editor-output-preview.js',
            'public/assets/vendor/model/model.tflite','public/assets/vendor/model/runtime.wasm','public/assets/vendor/model/LICENSE',
            '.htaccess','index.php','composer.json','composer.lock','LICENSE.md'] as $path) $this->put($path,'runtime-'.$path);
    }
    private function put(string $relative,string $contents): void
    {
        $path=$this->root.'/'.$relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
        file_put_contents($path,$contents);
    }
    protected function tearDown(): void
    {
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temporary,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isDir()) unlink($item->getPathname());
            else rmdir($item->getPathname());
        }
        rmdir($this->temporary);
    }
    public function testNestedSecretsTestsCachesAndMediaAreExcludedWhileRuntimeAndLicensesSurvive(): void
    {
        $excluded=['vendor/vendor-name/package/.env','vendor/vendor-name/package/tests/example.php',
            'vendor/vendor-name/package/Tests/Fixture.php','vendor/vendor-name/package/fixtures/canary.txt',
            'vendor/vendor-name/package/.git/config','vendor/vendor-name/package/auth.json',
            'vendor/vendor-name/package/credentials.json','vendor/vendor-name/package/private.key',
            'storage/private.php','public/storage/private.php','public/uploads/customer.png','public/assets/logs/debug.txt',
            'public/assets/.env.production','config/settings.php.bak','config/local.secret.php',
            'public/source.mp4','app/.codex/config.json','public/assets/node_modules/package/index.js',
            '.superpowers/evidence.php','app/backup/dump.php','vendor/vendor-name/package/backups/dump.php','public/assets/backup/leak.js',
            'app/Feature/tmp/secret.php','app/Module/uploads/payload.php',
            'vendor/acme/pkg/cache/session.json','vendor/acme/pkg/logs/debug.txt'];
        foreach ($excluded as $path) $this->put($path,'SECRET_RELEASE_CANARY');
        $output=$this->temporary.'/release.zip';
        (new ReleaseBuilder($this->root))->build($output);
        $zip=new ZipArchive();
        self::assertTrue($zip->open($output));
        foreach ($excluded as $path) self::assertFalse($zip->locateName($path),$path);
        foreach (['vendor/vendor-name/package/src/Runtime.php','vendor/vendor-name/package/LICENSE','public/assets/vendor/model/runtime.wasm',
            'public/assets/vendor/model/model.tflite','public/assets/vendor/model/LICENSE','vendor/composer/autoload_real.php','.htaccess','public/.htaccess','index.php'] as $path) {
            self::assertSame('runtime-'.$path,$zip->getFromName($path),$path);
        }
        for ($i=0;$i<$zip->numFiles;$i++) self::assertStringNotContainsString('SECRET_RELEASE_CANARY',(string)$zip->getFromIndex($i));
        $zip->close();
    }

    public function testLowercaseDataDirectoryUnderAppIsNotMistakenForStorageNamespace(): void
    {
        $source=$this->temporary.'/lowercase-source';
        mkdir($source.'/app/storage',0700,true);
        file_put_contents($source.'/app/App.php','runtime');
        file_put_contents($source.'/app/storage/private.php','PRIVATE_DATA_CANARY');
        (new ReleaseBuilder($source))->build($this->temporary.'/lowercase.zip');
        $zip=new ZipArchive();
        self::assertTrue($zip->open($this->temporary.'/lowercase.zip'));
        self::assertSame('runtime',$zip->getFromName('app/App.php'));
        self::assertFalse($zip->locateName('app/storage/private.php'));
        $zip->close();
    }

    public function testLegitimateStorageAndLogNamespacesAndNewRuntimeAssetsSurvive(): void
    {
        (new ReleaseBuilder($this->root))->build($this->temporary.'/runtime.zip');
        $zip=new ZipArchive();
        self::assertTrue($zip->open($this->temporary.'/runtime.zip'));
        foreach ([
            'app/Storage/LocalPrivateStorage.php','app/Storage/PrivateStagingArea.php','app/Storage/PrivateStagingFile.php',
            'vendor/Psr/Log/LoggerInterface.php','app/Media/OverlaySafeZone.php','app/Views/clips/editor.php',
            'public/assets/js/editor-output-preview.js',
        ] as $path) self::assertSame('runtime-'.$path,$zip->getFromName($path),$path);
        $zip->close();
    }
    public function testReleaseCarriesOnlyExplicitOperatorDocsAndTheSafeEnvironmentTemplate(): void
    {
        $this->put('.env.example', 'APP_ENV=production');
        $this->put('README.md', 'operator readme');
        $this->put('docs/HOSTINGER.md', 'deployment instructions');
        $this->put('docs/DELIVERY.md', 'verified delivery');
        $this->put('docs/HOMOLOGACAO_VIDEO_OFICIAL.md', 'official video evidence');
        $this->put('docs/PLATAFORMA_OPERACAO.md', 'platform operator instructions');
        $this->put('docs/RELATORIO_APRIMORAMENTO_2026-09-07.md', 'platform improvement report');
        $this->put('tools/activate-platform.php', '<?php // additive platform activation');
        $this->put('.env', 'PRIVATE_ENV_CANARY');
        $this->put('docs/private-customer-notes.md', 'PRIVATE_DOC_CANARY');
        (new ReleaseBuilder($this->root))->build($this->temporary.'/operator.zip');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->temporary.'/operator.zip'));
        foreach (['.env.example', 'README.md', 'docs/HOSTINGER.md', 'docs/DELIVERY.md', 'docs/HOMOLOGACAO_VIDEO_OFICIAL.md', 'docs/PLATAFORMA_OPERACAO.md', 'docs/RELATORIO_APRIMORAMENTO_2026-09-07.md', 'tools/activate-platform.php'] as $path) self::assertNotFalse($zip->locateName($path), $path);
        self::assertFalse($zip->locateName('.env'));
        self::assertFalse($zip->locateName('docs/private-customer-notes.md'));
        $zip->close();
    }

    public function testTwoBuildsHaveSameManifestAndEveryHashMatchesArchiveBytes(): void
    {
        $builder=new ReleaseBuilder($this->root);
        $first=$builder->build($this->temporary.'/first.zip');
        $second=$builder->build($this->temporary.'/second.zip');
        self::assertSame($first['manifest_sha256'],$second['manifest_sha256']);
        $zip=new ZipArchive();
        self::assertTrue($zip->open($this->temporary.'/first.zip'));
        $manifest=json_decode($zip->getFromName('release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $name=>$hash) self::assertSame($hash,hash('sha256',$zip->getFromName($name)));
        self::assertSame(count($manifest['files'])+1,$zip->numFiles);
        $zip->close();
    }
    public function testTraversalAndPublicStorageDestinationsAreRejectedWithoutCreatingFiles(): void
    {
        mkdir($this->temporary.'/public');
        mkdir($this->temporary.'/storage');
        foreach ([$this->temporary.'/source/../escape.zip',$this->root.'/release.zip',$this->temporary.'/public/release.zip',$this->temporary.'/storage/release.zip',
            $this->temporary.'/release.txt'] as $output) {
            try { (new ReleaseBuilder($this->root))->build($output); self::fail('Unsafe output accepted: '.$output); }
            catch (\InvalidArgumentException) { self::assertFileDoesNotExist($output); }
        }
    }
    public function testSymlinksAreNotFollowedAtAnyDepth(): void
    {
        mkdir($this->temporary.'/outside');
        file_put_contents($this->temporary.'/outside/secret.php','SYMLINK_CANARY');
        if (!@symlink($this->temporary.'/outside',$this->root.'/app/external')) {
            self::markTestSkipped('Host does not permit unprivileged symlinks.');
        }
        @symlink($this->temporary.'/outside/secret.php',$this->root.'/public/linked.php');
        (new ReleaseBuilder($this->root))->build($this->temporary.'/links.zip');
        $zip=new ZipArchive(); $zip->open($this->temporary.'/links.zip');
        self::assertFalse($zip->locateName('app/external/secret.php'));
        self::assertFalse($zip->locateName('public/linked.php'));
        $zip->close();
    }
}
