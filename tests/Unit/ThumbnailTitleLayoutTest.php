<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\Thumbnails\LocalThumbnailGenerator;
use App\Media\Thumbnails\ThumbnailOptions;
use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class ThumbnailTitleLayoutTest extends TestCase
{
    public function testSplitShrinksTheFontBeforeBreakingAWordingThatCanFit(): void
    {
        [$text, $size] = $this->layout('Fidelidade em debate', 'split', 64);
        self::assertSame("Fidelidade\nem debate", $text);
        self::assertGreaterThanOrEqual(32, $size);
        self::assertLessThan(64, $size);
    }

    public function testSplitPreservesUnicodeWordsWhenShrinking(): void
    {
        [$text, $size] = $this->layout('Solidariedade e ação', 'split', 64);
        self::assertSame("Solidariedade\ne ação", $text);
        self::assertGreaterThanOrEqual(32, $size);
        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    public function testUnbreakableMaximumTitleFallsBackAtTheMinimumFontWithoutLosingUnicode(): void
    {
        $title = str_repeat('é', 120);
        [$text, $size] = $this->layout($title, 'split', 33);
        self::assertSame(32, $size, 'An odd requested size must stop at the supported minimum.');
        self::assertSame($title, str_replace("\n", '', $text));
        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        foreach (explode("\n", $text) as $line) self::assertLessThanOrEqual(16, mb_strlen($line));
        self::assertLessThanOrEqual(600, count(explode("\n", $text)) * ($size * 1.25 + 8));
    }

    public function testWideTemplatesKeepTheirRequestedFontWhenTheWordsFit(): void
    {
        foreach (['clean', 'bold'] as $template) {
            [$text, $size] = $this->layout('Fidelidade em debate', $template, 64);
            self::assertSame("Fidelidade em\ndebate", $text);
            self::assertSame(64, $size);
        }
    }

    public function testForcedBreaksAreReflowedOnlyWhenTheyCannotFitAtTheMinimumSize(): void
    {
        $title = implode("\n", array_fill(0, 60, 'é'));
        [$text, $size] = $this->layout($title, 'split', 33);
        self::assertSame(32, $size);
        self::assertSame(array_fill(0, 60, 'é'), preg_split('/\s+/u', $text));
        self::assertLessThanOrEqual(12, count(explode("\n", $text)));
        foreach (explode("\n", $text) as $line) self::assertLessThanOrEqual(16, mb_strlen($line));
        self::assertLessThanOrEqual(600, count(explode("\n", $text)) * ($size * 1.25 + 8));
    }

    public function testIntentionalBreaksArePreservedWhenTheyFit(): void
    {
        [$text, $size] = $this->layout("Primeira\nSegunda", 'split', 64);
        self::assertSame("Primeira\nSegunda", $text);
        self::assertSame(64, $size);
    }

    public function testTitleThatStillCannotFitIsRejectedBeforeRenderingInsteadOfClipped(): void
    {
        [$text, $size] = $this->layout(implode(' ', array_fill(0, 13, 'WWWWWWWW')), 'split', 64,
            'Thumbnail title exceeds the supported layout bounds.');
        self::assertSame('', $text);
        self::assertSame(0, $size);
    }

    /** Capture the actual title file and drawtext font passed to the external renderer. */
    private function layout(string $title, string $template, int $fontSize, string $expectedFailure = 'Captured title before FFmpeg execution.'): array
    {
        $root = sys_get_temp_dir() . '/thumbnail-layout-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        file_put_contents($root . '/source.mp4', 'fixture');
        $runner = new class($root) extends ProcessRunner {
            public string $text = '';
            public int $fontSize = 0;
            public function __construct(private string $root) { parent::__construct(['ffmpeg'], $root); }
            public function run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult
            {
                $files = glob($this->root . '/thumbnail-*.txt');
                $this->text = file_get_contents($files[0]);
                $filter = $command[array_search('-filter_complex', $command, true) + 1];
                preg_match('/:fontsize=([0-9]+):/', $filter, $match);
                $this->fontSize = (int) $match[1];
                throw new \RuntimeException('Captured title before FFmpeg execution.');
            }
        };
        try {
            $generator = new LocalThumbnailGenerator(new LocalPrivateStorage($root, 2097152), $runner, 'ffmpeg', $root);
            try {
                $generator->design(['source_object_key' => 'source.mp4', 'render_start_time' => 0, 'render_end_time' => 1, 'project_id' => 1], 0,
                    ThumbnailOptions::fromArray(['title' => $title, 'template' => $template, 'font_size' => $fontSize, 'font_family' => 'Verdana']));
                self::fail('The recording runner must stop before rendering.');
            } catch (\RuntimeException $error) {
                self::assertSame($expectedFailure, $error->getMessage());
            }
            self::assertSame([], glob($root . '/thumbnail-*.txt'));
            return [$runner->text, $runner->fontSize];
        } finally {
            foreach (glob($root . '/*') ?: [] as $file) unlink($file);
            rmdir($root);
        }
    }
}
