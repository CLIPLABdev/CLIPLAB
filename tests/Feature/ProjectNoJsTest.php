<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ProjectCreator;
use App\Controllers\ProjectController;
use App\Core\View;
use App\Media\ProjectReceipt;
use PHPUnit\Framework\TestCase;

final class ProjectNoJsTest extends TestCase
{
    public function testCreationFormKeepsBothSourceModesUsableWithoutJavascript(): void
    {
        $_SESSION = ['user_id' => 9];
        $html = (new ProjectController(new View(), new NoJsProjectCreator(), static fn (): array => []))->create()->body();

        self::assertStringContainsString('class="source-choice"', $html);
        self::assertStringContainsString('name="source_type" value="upload"', $html);
        self::assertStringContainsString('name="source_type" value="direct_url"', $html);
        self::assertStringContainsString('Sem JavaScript, escolha uma origem acima', $html);
        self::assertStringNotContainsString('fallback-video-file', $html);
    }
}

final class NoJsProjectCreator implements ProjectCreator
{
    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt { return new ProjectReceipt(1, 'queued', true); }
    public function fromDirectUrl(int $userId, array $input): ProjectReceipt { return new ProjectReceipt(1, 'queued', true); }
}
