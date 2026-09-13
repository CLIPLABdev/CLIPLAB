<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\ValidatedYoutubeUrl;
use App\Media\YoutubeUrlValidator;
use PHPUnit\Framework\TestCase;

final class YoutubeUrlValidatorTest extends TestCase
{
    /** @dataProvider supportedUrls */
    public function testCanonicalizesSupportedSingleVideoUrls(string $input): void
    {
        $url = (new YoutubeUrlValidator())->validate($input);

        self::assertInstanceOf(ValidatedYoutubeUrl::class, $url);
        self::assertSame('dQw4w9WgXcQ', $url->videoId());
        self::assertSame('www.youtube.com', $url->host());
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $url->url());
    }

    /** @return iterable<string, array{string}> */
    public static function supportedUrls(): iterable
    {
        yield 'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'mobile with harmless share parameters' => ['https://m.youtube.com/watch?si=share&v=dQw4w9WgXcQ&t=12'];
        yield 'short link' => ['https://youtu.be/dQw4w9WgXcQ?si=share'];
        yield 'shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ'];
        yield 'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ'];
    }

    /** @dataProvider rejectedYoutubeUrls */
    public function testRejectsAnythingExceptAnExactPublicSingleVideoShape(string $input): void
    {
        try {
            (new YoutubeUrlValidator())->validate($input);
            self::fail('Unsupported YouTube URL was accepted.');
        } catch (MediaValidationException $exception) {
            self::assertSame('unsupported_youtube_url', $exception->publicCode());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedYoutubeUrls(): iterable
    {
        yield 'playlist' => ['https://www.youtube.com/playlist?list=PL123'];
        yield 'watch with playlist' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123'];
        yield 'channel' => ['https://www.youtube.com/channel/UC123'];
        yield 'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ'];
        yield 'wrong id' => ['https://youtu.be/not-valid'];
        yield 'credentials' => ['https://user:pass@youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'custom port' => ['https://youtube.com:444/watch?v=dQw4w9WgXcQ'];
        yield 'fragment' => ['https://youtu.be/dQw4w9WgXcQ#fragment'];
        yield 'lookalike' => ['https://youtube.com.example.test/watch?v=dQw4w9WgXcQ'];
        yield 'http' => ['http://youtube.com/watch?v=dQw4w9WgXcQ'];
    }

    public function testRecognizesOnlyExactYoutubeHostsBeforeValidation(): void
    {
        $validator = new YoutubeUrlValidator();

        self::assertTrue($validator->recognizes('https://youtu.be/not-valid'));
        self::assertTrue($validator->recognizes('https://www.youtube.com/channel/UC123'));
        self::assertFalse($validator->recognizes('https://youtube.com.example.test/video.mp4'));
        self::assertFalse($validator->recognizes('https://cdn.example.test/video.mp4'));
    }
}
