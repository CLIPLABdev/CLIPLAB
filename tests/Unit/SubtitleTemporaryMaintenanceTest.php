<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Contracts\RenderArtifactCleanupStore;
use App\Services\RenderMaintenance;
use PHPUnit\Framework\TestCase;

final class SubtitleTemporaryMaintenanceTest extends TestCase
{
    public function testRecoversOnlyStaleInternallyNamedAudioAndSubtitleArtifacts(): void
    {
        $dir=sys_get_temp_dir().'/subtitle-maintenance-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $audio=$dir.'/cliplab-audio-'.str_repeat('a',32).'.wav';
        $ass=$dir.'/cliplab-subtitles-'.str_repeat('b',32).'.ass';
        $recent=$dir.'/cliplab-audio-'.str_repeat('c',32).'.wav';
        $other=$dir.'/user-source.wav';
        try {
            foreach ([$audio,$ass,$recent,$other] as $file) file_put_contents($file,'test');
            foreach ([$audio,$ass,$other] as $file) touch($file,time()-100);
            $cleanups=$this->createMock(RenderArtifactCleanupStore::class);
            $cleanups->method('pending')->willReturn([]);
            (new RenderMaintenance($cleanups,$this->createMock(PrivateStorage::class),$dir,60))->run();
            self::assertFileDoesNotExist($audio);
            self::assertFileDoesNotExist($ass);
            self::assertFileExists($recent);
            self::assertFileExists($other);
        } finally {
            foreach ([$audio,$ass,$recent,$other] as $file) if (is_file($file)) unlink($file);
            rmdir($dir);
        }
    }
}
