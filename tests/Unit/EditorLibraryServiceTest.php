<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Media\Editor\EditorTemplateCatalog;
use App\Repositories\EditorLibraryRepository;
use App\Services\EditorLibraryService;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\EditorLibraryFixture;

final class EditorLibraryServiceTest extends TestCase
{
    use EditorLibraryFixture;
    private string $root;
    protected function setUp(): void { $this->root = sys_get_temp_dir() . '/cliplab-brand-test-' . bin2hex(random_bytes(8)); mkdir($this->root); }
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }
    private function service(?callable $quota = null): array
    {
        $pdo = $this->libraryDatabase(); $repo = new EditorLibraryRepository($pdo);
        $storage = new LocalPrivateStorage($this->root, 2097152, static fn (): bool => true);
        $service = new EditorLibraryService($repo, $storage, $quota ?? static function (int $id, int $bytes) use ($pdo): void {
            self::assertTrue($pdo->inTransaction()); self::assertSame(1, $id); self::assertGreaterThan(0, $bytes);
        }, static fn (): bool => true);
        return [$service, $repo, $storage];
    }
    private function upload(string $bytes): array
    {
        $path = $this->root . '/upload-' . bin2hex(random_bytes(4)); file_put_contents($path, $bytes);
        return ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'name' => '../../logo.png', 'type' => 'image/png'];
    }
    public function testCatalogUsesRealStylesAndNormalizedOptionsOnly(): void
    {
        $presets = EditorTemplateCatalog::all();
        self::assertCount(5, $presets);
        self::assertSame(['viral', 'podcast', 'clean', 'impact', 'custom'], array_column($presets, 'id'));
        self::assertSame('minimal', $presets[2]['options']['style']);
        self::assertSame('highlight', $presets[3]['options']['style']);
        self::assertSame('Arial', $presets[2]['options']['font_family']);
        self::assertArrayNotHasKey('srt', $presets[0]['options']);
    }
    public function testUploadKeepsImmutableOwnedBytesAndOnlyPublishesOwnedHttpUrls(): void
    {
        [$service, $repo, $storage] = $this->service();
        $id = $service->uploadLogo(1, $this->upload($this->png()));
        $logo = $repo->findLogoOwned($id, 1);
        self::assertNotNull($logo); self::assertNull($repo->findLogoOwned($id, 2));
        self::assertNull($repo->resolveLogoForProject($id, 20));
        self::assertSame($logo['object_key'], $repo->resolveLogoForProject($id, 10));
        self::assertSame($this->png(), file_get_contents($storage->absolutePath($logo['object_key'])));
        self::assertSame(strlen($this->png()), $repo->brandLogoBytes(1));
        $catalog = $service->catalog(1);
        self::assertSame('/marca/logos/' . $id, $catalog['logos'][0]['url']);
        self::assertArrayNotHasKey('object_key', $catalog['logos'][0]);
        self::assertSame([], $service->catalog(2)['logos']);
    }
    public function testRejectsInvalidPngBeforeWritingAnythingOrCallingQuota(): void
    {
        [$service, $repo] = $this->service(static function (): void { self::fail('Invalid file reached quota admission'); });
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"/>', "\x89PNG\r\n\x1a\ntruncated", substr_replace($this->png(), 'X', 45, 1), str_repeat('x', 2097153)] as $bytes) {
            try { $service->uploadLogo(1, $this->upload($bytes)); self::fail('Invalid PNG accepted'); }
            catch (\InvalidArgumentException) {}
        }
        self::assertSame([], $repo->listLogosOwned(1)); self::assertDirectoryDoesNotExist($this->root . '/brand');
    }
    public function testRejectsSixthLogoAndQuotaFailureLeavesNoAssets(): void
    {
        [$service, $repo] = $this->service();
        for ($i = 0; $i < 5; $i++) $service->uploadLogo(1, $this->upload($this->png()));
        try { $service->uploadLogo(1, $this->upload($this->png())); self::fail('Sixth logo accepted'); } catch (\DomainException) {}
        self::assertCount(5, $repo->listLogosOwned(1));
        [$blocked, $empty] = $this->service(static function (): void { throw new \DomainException('Quota exceeded'); });
        try { $blocked->uploadLogo(1, $this->upload($this->png())); self::fail('Quota bypassed'); } catch (\DomainException) {}
        self::assertSame([], $empty->listLogosOwned(1));
    }
    public function testOptionsCannotSmuggleFiltersOrForeignAssets(): void
    {
        [$service] = $this->service();
        foreach ([['font_family' => 'Unknown'], ['filter_complex' => 'movie=/etc/passwd'], ['logo_asset_id' => 987], ['font_size' => '44oops']] as $options) {
            try { $service->saveTemplate(1, ['name' => 'Teste', 'category' => 'clean', 'aspect_ratio' => '9:16', 'options' => $options]); self::fail('Invalid options accepted'); }
            catch (\InvalidArgumentException | \OutOfBoundsException) {}
        }
        self::assertSame([], $service->catalog(1)['templates']);
    }

    public function testEveryVisualOptionSurvivesServiceTemplateAndKitReload(): void
    {
        [$service,$repo]=$this->service();
        $logo=$service->uploadLogo(1,$this->upload($this->png()));
        $expected=['style'=>'highlight','position'=>'top','color'=>'#112233','accent_color'=>'#445566','font_size'=>53,
            'title'=>'Título','watermark'=>'Marca','font_family'=>'Verdana','background_color'=>'#778899','outline_width'=>4,
            'shadow_depth'=>3,'font_weight'=>'bold','animation'=>'pop','cta_text'=>'Siga','logo_asset_id'=>$logo,
            'logo_position'=>'bottom_left','logo_scale'=>17,'font_italic'=>1,'letter_spacing'=>5,'caption_margin_x'=>31,
            'caption_offset_y'=>-37,'outline_color'=>'#ABCDEF','background_mode'=>'box','animation_duration_ms'=>287,
            'animation_out'=>'fade','animation_out_duration_ms'=>419,'video_fade_in_ms'=>631,'video_fade_out_ms'=>823,
            'brightness'=>-19,'contrast'=>121,'saturation'=>143,'blur'=>7,'noise'=>11,'vignette'=>47,'zoom_percent'=>129,'motion'=>'zoom_out'];
        $id=$service->saveTemplate(1,['name'=>'Completo','category'=>'custom','aspect_ratio'=>'1:1','options'=>array_map('strval',$expected)]);
        self::assertSame($expected,$repo->snapshot($id,1)['options']);
        $service->applyTemplate(1,$id);
        self::assertSame($expected,$service->catalog(1)['kit']['options']);
        self::assertSame('1:1',$service->catalog(1)['kit']['aspect_ratio']);
        $expected['brightness']=23;
        $service->saveKit(1,['options'=>$expected,'aspect_ratio'=>'16:9','favorites'=>[$id]]);
        self::assertSame($expected,$service->catalog(1)['kit']['options']);
        self::assertSame(-19,$repo->snapshot($id,1)['options']['brightness']);
    }

    public function testIndexedPngWithoutPaletteIsNotAValidLogo(): void
    {
        [$service, $repo] = $this->service();
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $bytes = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 3, 0, 0, 0)) . $chunk('IDAT', gzcompress("\0\0")) . $chunk('IEND', '');
        $this->expectException(\InvalidArgumentException::class);
        $service->uploadLogo(1, $this->upload($bytes));
    }

    public function testDimensionsAndUploadStatusAreCheckedBeforeStorage(): void
    {
        [$service, $repo] = $this->service();
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $bytes = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 2049, 1, 8, 6, 0, 0, 0)) . $chunk('IDAT', gzcompress("\0" . str_repeat("\xff", 2049 * 4))) . $chunk('IEND', '');
        foreach ([$this->upload($bytes), ['tmp_name' => 'missing', 'error' => UPLOAD_ERR_INI_SIZE]] as $file) {
            try { $service->uploadLogo(1, $file); self::fail('Invalid upload accepted'); } catch (\InvalidArgumentException) {}
        }
        self::assertSame([], $repo->listLogosOwned(1));
        self::assertDirectoryDoesNotExist($this->root . '/brand');
    }
}
