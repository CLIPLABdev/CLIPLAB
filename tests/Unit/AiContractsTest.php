<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\AiAnalysisReceipt;
use App\Ai\AiAnalysisResult;
use App\Ai\AiClipSuggestion;
use App\Contracts\AiPipelineScheduler;
use App\Contracts\VideoAnalysisProvider;
use App\Gemini\GeminiFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AiContractsTest extends TestCase
{
    public function testVideoProviderExposesStableTypedOperations(): void
    {
        $upload = new ReflectionMethod(VideoAnalysisProvider::class, 'upload');
        $getFile = new ReflectionMethod(VideoAnalysisProvider::class, 'getFile');
        $generate = new ReflectionMethod(VideoAnalysisProvider::class, 'generate');
        $delete = new ReflectionMethod(VideoAnalysisProvider::class, 'deleteFile');

        self::assertSame('App\\Media\\ProjectSource', (string) $upload->getParameters()[0]->getType());
        self::assertSame('App\\Gemini\\GeminiFile', (string) $upload->getReturnType());
        self::assertSame('string', (string) $getFile->getParameters()[0]->getType());
        self::assertSame('App\\Gemini\\GeminiFile', (string) $getFile->getReturnType());
        self::assertSame(['App\\Gemini\\GeminiFile', 'string', 'array'], array_map(
            static fn ($parameter): string => (string) $parameter->getType(),
            $generate->getParameters()
        ));
        self::assertSame('string', (string) $generate->getReturnType());
        self::assertSame('void', (string) $delete->getReturnType());
    }

    public function testPipelineSchedulerExposesStableTypedOperation(): void
    {
        $schedule = new ReflectionMethod(AiPipelineScheduler::class, 'schedule');

        self::assertSame(['int', 'int', 'int'], array_map(
            static fn ($parameter): string => (string) $parameter->getType(),
            $schedule->getParameters()
        ));
        self::assertSame('App\\Ai\\AiAnalysisReceipt', (string) $schedule->getReturnType());
    }

    public function testGeminiFileKeepsOnlyValidatedProviderMetadata(): void
    {
        $file = new GeminiFile(
            'files/video-123',
            'https://generativelanguage.googleapis.com/v1beta/files/video-123',
            'video/mp4',
            'ACTIVE'
        );

        self::assertSame('files/video-123', $file->name());
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/files/video-123', $file->uri());
        self::assertSame('video/mp4', $file->mimeType());
        self::assertSame('ACTIVE', $file->state());
    }

    public function testGeminiFileAcceptsResourceNameAndHttpsPortBoundaries(): void
    {
        $short = new GeminiFile(
            'files/a',
            'https://generativelanguage.googleapis.com:443/v1beta/files/a',
            'video/mp4',
            'PROCESSING'
        );
        $longName = 'files/' . 'a' . str_repeat('-', 38) . '9';
        $long = new GeminiFile(
            $longName,
            'https://generativelanguage.googleapis.com/v1beta/files/long',
            'video/webm',
            'FAILED'
        );

        self::assertSame('files/a', $short->name());
        self::assertSame($longName, $long->name());
    }

    /** @dataProvider invalidGeminiFiles */
    public function testGeminiFileRejectsUnsafeOrUnsupportedMetadata(
        string $name,
        string $uri,
        string $mime,
        string $state
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new GeminiFile($name, $uri, $mime, $state);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public function invalidGeminiFiles(): iterable
    {
        $validUri = 'https://generativelanguage.googleapis.com/v1beta/files/video-123';

        yield 'missing file id' => ['files/', $validUri, 'video/mp4', 'ACTIVE'];
        yield 'uppercase file id' => ['files/Video-123', $validUri, 'video/mp4', 'ACTIVE'];
        yield 'file id starts with hyphen' => ['files/-video', $validUri, 'video/mp4', 'ACTIVE'];
        yield 'file id ends with hyphen' => ['files/video-', $validUri, 'video/mp4', 'ACTIVE'];
        yield 'file id above 40 characters' => ['files/' . str_repeat('a', 41), $validUri, 'video/mp4', 'ACTIVE'];
        yield 'wrong resource prefix' => ['uploads/video-123', $validUri, 'video/mp4', 'ACTIVE'];
        yield 'non https uri' => ['files/video-123', 'http://generativelanguage.googleapis.com/v1beta/files/video-123', 'video/mp4', 'ACTIVE'];
        yield 'foreign provider host' => ['files/video-123', 'https://example.test/v1beta/files/video-123', 'video/mp4', 'ACTIVE'];
        yield 'provider lookalike host' => ['files/video-123', 'https://generativelanguage.googleapis.com.example.test/file', 'video/mp4', 'ACTIVE'];
        yield 'uri with user info' => ['files/video-123', 'https://user@generativelanguage.googleapis.com/file', 'video/mp4', 'ACTIVE'];
        yield 'uri with fragment' => ['files/video-123', $validUri . '#fragment', 'video/mp4', 'ACTIVE'];
        yield 'non default https port' => ['files/video-123', 'https://generativelanguage.googleapis.com:444/file', 'video/mp4', 'ACTIVE'];
        yield 'unsupported mime' => ['files/video-123', $validUri, 'text/plain', 'ACTIVE'];
        yield 'mime parameters' => ['files/video-123', $validUri, 'video/mp4; charset=binary', 'ACTIVE'];
        yield 'unsupported state' => ['files/video-123', $validUri, 'video/mp4', 'STATE_UNSPECIFIED'];
    }

    /** @dataProvider supportedVideoMimes */
    public function testGeminiFileAcceptsEverySupportedIngestMime(string $mime): void
    {
        $file = new GeminiFile(
            'files/a1',
            'https://storage.googleapis.com/generativelanguage-files/a1',
            $mime,
            'PROCESSING'
        );

        self::assertSame($mime, $file->mimeType());
    }

    /** @return iterable<string, array{string}> */
    public function supportedVideoMimes(): iterable
    {
        yield 'mp4' => ['video/mp4'];
        yield 'application mp4' => ['application/mp4'];
        yield 'quicktime' => ['video/quicktime'];
        yield 'webm' => ['video/webm'];
    }

    public function testClipSuggestionIsImmutableAndBoundedForPersistence(): void
    {
        $clip = $this->clip(0);

        self::assertSame(0, $clip->index());
        self::assertSame('Um título', $clip->title());
        self::assertSame(10.5, $clip->startTime());
        self::assertSame(40.5, $clip->endTime());
        self::assertSame(30.0, $clip->duration());
        self::assertSame(92, $clip->score());
        self::assertSame('Motivo', $clip->reason());
        self::assertSame('Gancho', $clip->hook());
        self::assertSame('educational', $clip->category());
    }

    public function testClipSuggestionAcceptsExactlyThreeDecimalTimelineValues(): void
    {
        $clip = new AiClipSuggestion(0, 'Title', 10.125, 40.125, 30.000, 50, 'Reason', 'Hook', 'other');

        self::assertSame(10.125, $clip->startTime());
        self::assertSame(40.125, $clip->endTime());
        self::assertSame(30.0, $clip->duration());
    }

    /** @dataProvider invalidClipSuggestions */
    public function testClipSuggestionRejectsInvalidDatabaseValues(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /** @return iterable<string, array{callable(): AiClipSuggestion}> */
    public function invalidClipSuggestions(): iterable
    {
        yield 'negative index' => [fn (): AiClipSuggestion => $this->clip(-1)];
        yield 'index above nine' => [fn (): AiClipSuggestion => $this->clip(10)];
        yield 'empty title' => [fn (): AiClipSuggestion => $this->clip(0, '')];
        yield 'title above column limit' => [fn (): AiClipSuggestion => $this->clip(0, str_repeat('a', 181))];
        yield 'hook above column limit' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, 20.0, 20.0, 1, 'Reason', str_repeat('a', 501), 'other')];
        yield 'reason above column limit' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, 20.0, 20.0, 1, str_repeat('a', 1001), 'Hook', 'other')];
        yield 'control character' => [fn (): AiClipSuggestion => $this->clip(0, "bad\x07title")];
        yield 'negative start' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', -0.1, 20.0, 20.1, 1, 'Reason', 'Hook', 'other')];
        yield 'non finite start' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', NAN, 20.0, 20.0, 1, 'Reason', 'Hook', 'other')];
        yield 'non finite end' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, INF, 20.0, 1, 'Reason', 'Hook', 'other')];
        yield 'non finite duration' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, 20.0, NAN, 1, 'Reason', 'Hook', 'other')];
        yield 'equal bounds' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 20.0, 20.0, 1.0, 1, 'Reason', 'Hook', 'other')];
        yield 'negative duration' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, 20.0, -1.0, 1, 'Reason', 'Hook', 'other')];
        yield 'incoherent duration' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, 20.0, 19.0, 1, 'Reason', 'Hook', 'other')];
        yield 'timestamp above decimal column' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, 10000000.0, 10000000.0, 1, 'Reason', 'Hook', 'other')];
        yield 'start exceeds decimal precision' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 10.1234, 40.123, 30.0, 1, 'Reason', 'Hook', 'other')];
        yield 'end exceeds decimal precision' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 10.123, 40.1234, 30.0, 1, 'Reason', 'Hook', 'other')];
        yield 'duration exceeds decimal precision' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 10.123, 40.123, 30.0004, 1, 'Reason', 'Hook', 'other')];
        yield 'negative score' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, 20.0, 20.0, -1, 'Reason', 'Hook', 'other')];
        yield 'score above one hundred' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, 20.0, 20.0, 101, 'Reason', 'Hook', 'other')];
        yield 'unsupported category' => [fn (): AiClipSuggestion => new AiClipSuggestion(0, 'Title', 0.0, 20.0, 20.0, 1, 'Reason', 'Hook', 'viral')];
    }

    public function testAnalysisResultRequiresAnOrderedUniqueClipList(): void
    {
        $first = $this->clip(0);
        $second = new AiClipSuggestion(1, 'Segundo', 50.0, 70.0, 20.0, 80, 'Motivo 2', 'Gancho 2', 'story');
        $result = new AiAnalysisResult('Resumo seguro', [$first, $second]);

        self::assertSame('Resumo seguro', $result->videoSummary());
        self::assertSame([$first, $second], $result->clips());
    }

    public function testAnalysisResultAcceptsExactlyTenOrderedClips(): void
    {
        $clips = [];
        for ($index = 0; $index < 10; $index++) {
            $clips[] = $this->clip($index);
        }

        $result = new AiAnalysisResult('Ten suggestions', $clips);

        self::assertCount(10, $result->clips());
        self::assertSame(9, $result->clips()[9]->index());
    }

    /** @dataProvider invalidAnalysisResults */
    public function testAnalysisResultRejectsInvalidSummaryOrClipList(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /** @return iterable<string, array{callable(): AiAnalysisResult}> */
    public function invalidAnalysisResults(): iterable
    {
        yield 'empty summary' => [fn (): AiAnalysisResult => new AiAnalysisResult('', [$this->clip(0)])];
        yield 'oversized summary' => [fn (): AiAnalysisResult => new AiAnalysisResult(str_repeat('a', 2001), [$this->clip(0)])];
        yield 'summary control character' => [fn (): AiAnalysisResult => new AiAnalysisResult("bad\x00summary", [$this->clip(0)])];
        yield 'no clips' => [fn (): AiAnalysisResult => new AiAnalysisResult('Summary', [])];
        yield 'more than ten clips' => [fn (): AiAnalysisResult => new AiAnalysisResult('Summary', array_fill(0, 11, $this->clip(0)))];
        yield 'non list keys' => [fn (): AiAnalysisResult => new AiAnalysisResult('Summary', [1 => $this->clip(0)])];
        yield 'wrong member type' => [fn (): AiAnalysisResult => new AiAnalysisResult('Summary', [$this->clip(0), new \stdClass()])];
        yield 'out of order index' => [fn (): AiAnalysisResult => new AiAnalysisResult('Summary', [$this->clip(1), $this->clip(0)])];
        yield 'duplicate index' => [fn (): AiAnalysisResult => new AiAnalysisResult('Summary', [$this->clip(0), $this->clip(0)])];
    }

    public function testAnalysisReceiptKeepsBoundedSchedulingState(): void
    {
        $receipt = new AiAnalysisReceipt(12, 34, 'queued', true);

        self::assertSame(12, $receipt->analysisId());
        self::assertSame(34, $receipt->reservationId());
        self::assertSame('queued', $receipt->status());
        self::assertTrue($receipt->created());

        $withoutReservation = new AiAnalysisReceipt(12, null, 'queued', false);
        self::assertNull($withoutReservation->reservationId());
    }

    /** @dataProvider invalidReceipts */
    public function testAnalysisReceiptRejectsInvalidIdentifiersOrState(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /** @return iterable<string, array{callable(): AiAnalysisReceipt}> */
    public function invalidReceipts(): iterable
    {
        yield 'invalid analysis id' => [static fn (): AiAnalysisReceipt => new AiAnalysisReceipt(0, null, 'queued', true)];
        yield 'invalid reservation id' => [static fn (): AiAnalysisReceipt => new AiAnalysisReceipt(1, 0, 'queued', true)];
        yield 'project awaiting credits is not analysis status' => [static fn (): AiAnalysisReceipt => new AiAnalysisReceipt(1, null, 'awaiting_credits', true)];
        yield 'invalid status' => [static fn (): AiAnalysisReceipt => new AiAnalysisReceipt(1, null, 'unknown', true)];
    }

    private function clip(int $index, string $title = 'Um título'): AiClipSuggestion
    {
        return new AiClipSuggestion($index, $title, 10.5, 40.5, 30.0, 92, 'Motivo', 'Gancho', 'educational');
    }
}
