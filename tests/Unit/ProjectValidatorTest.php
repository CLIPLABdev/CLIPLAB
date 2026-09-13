<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\ProjectValidator;
use PHPUnit\Framework\TestCase;

final class ProjectValidatorTest extends TestCase
{
    public function testRequiresTheSourceSelectedByTheUser(): void
    {
        self::assertArrayHasKey('video_file', ProjectValidator::creation([
            'name' => 'Entrevista',
            'source_type' => 'upload',
            'source_url' => 'https://example.com/video.mp4',
        ], []));

        self::assertArrayHasKey('source_url', ProjectValidator::creation([
            'name' => 'Entrevista',
            'source_type' => 'direct_url',
        ], []));
    }

    public function testRejectsInvalidProjectDisplayFields(): void
    {
        $errors = ProjectValidator::creation([
            'name' => ' ',
            'source_type' => 'remote_file',
        ], []);

        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('source_type', $errors);
    }

    public function testRejectsNonHttpsOrCredentialedDirectUrlsBeforeCreation(): void
    {
        foreach (['http://example.com/video.mp4', 'https://user:pass@example.com/video.mp4'] as $url) {
            $errors = ProjectValidator::creation([
                'name' => 'Entrevista',
                'source_type' => 'direct_url',
                'source_url' => $url,
            ], []);

            self::assertArrayHasKey('source_url', $errors);
        }
    }

    public function testRequiresOwnershipConsentForYoutubeButNotForDirectMedia(): void
    {
        $youtube = ProjectValidator::creation([
            'name' => 'Vídeo público',
            'source_type' => 'direct_url',
            'source_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ], []);
        self::assertArrayHasKey('youtube_rights_confirmed', $youtube);

        $consented = ProjectValidator::creation([
            'name' => 'Vídeo público',
            'source_type' => 'direct_url',
            'source_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'youtube_rights_confirmed' => '1',
        ], []);
        self::assertArrayNotHasKey('youtube_rights_confirmed', $consented);

        $direct = ProjectValidator::creation([
            'name' => 'Vídeo direto',
            'source_type' => 'direct_url',
            'source_url' => 'https://cdn.example.test/video.mp4',
        ], []);
        self::assertArrayNotHasKey('youtube_rights_confirmed', $direct);
    }
}
