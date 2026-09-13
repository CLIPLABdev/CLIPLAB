<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\AnalysisResponseValidator;
use App\Ai\InvalidAnalysisResponse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AnalysisResponseValidatorTest extends TestCase
{
    private AnalysisResponseValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AnalysisResponseValidator();
    }

    public function testBuildsTypedOrderedResultAndPreservesAcceptedText(): void
    {
        $json = $this->json([
            'video_summary' => "  Resumo seguro\n",
            'clips' => [
                $this->clip([
                    'title' => "  Primeiro corte\t",
                    'start_time' => 20.5,
                    'end_time' => 50.5,
                    'duration' => 30.0,
                    'score' => 92,
                    'reason' => "Motivo\nseguro",
                    'hook' => 'Gancho seguro',
                    'category' => 'educational',
                ]),
                $this->clip([
                    'title' => 'Segundo corte',
                    'start_time' => 45.0,
                    'end_time' => 70.0,
                    'duration' => 25.0,
                    'score' => 88.0,
                    'reason' => 'Motivo dois',
                    'hook' => 'Gancho dois',
                    'category' => 'story',
                ]),
            ],
        ], JSON_PRESERVE_ZERO_FRACTION);

        $result = $this->validator->validate($json, 180);

        self::assertSame("  Resumo seguro\n", $result->videoSummary());
        self::assertCount(2, $result->clips());
        self::assertSame(0, $result->clips()[0]->index());
        self::assertSame("  Primeiro corte\t", $result->clips()[0]->title());
        self::assertSame(20.5, $result->clips()[0]->startTime());
        self::assertSame(92, $result->clips()[0]->score());
        self::assertSame(1, $result->clips()[1]->index());
        self::assertSame(88, $result->clips()[1]->score());
    }

    public function testPreservesNormalTextThatContainsAnEmbeddedFormatCharacter(): void
    {
        $summary = "Resumo\u{200B}normal";
        $title = "Corte\u{2060}normal";
        $json = $this->json([
            'video_summary' => $summary,
            'clips' => [$this->clip(['title' => $title])],
        ]);

        $result = $this->validator->validate($json, 180);

        self::assertSame($summary, $result->videoSummary());
        self::assertSame($title, $result->clips()[0]->title());
    }

    public function testPreservesProviderOrderWithoutSortingTheTimeline(): void
    {
        $json = $this->json([
            'video_summary' => 'Resumo seguro',
            'clips' => [
                $this->clip(['title' => 'Mais tarde', 'start_time' => 60, 'end_time' => 80]),
                $this->clip(['title' => 'Mais cedo', 'start_time' => 10, 'end_time' => 30]),
            ],
        ]);

        $result = $this->validator->validate($json, 180);

        self::assertSame('Mais tarde', $result->clips()[0]->title());
        self::assertSame(0, $result->clips()[0]->index());
        self::assertSame('Mais cedo', $result->clips()[1]->title());
        self::assertSame(1, $result->clips()[1]->index());
    }

    /** @dataProvider allowedCategories */
    public function testAcceptsEveryAllowlistedCategory(string $category): void
    {
        $result = $this->validator->validate($this->validJson(['category' => $category]), 180);

        self::assertSame($category, $result->clips()[0]->category());
    }

    /** @return iterable<string, array{string}> */
    public function allowedCategories(): iterable
    {
        yield 'educational' => ['educational'];
        yield 'story' => ['story'];
        yield 'emotional' => ['emotional'];
        yield 'humorous' => ['humorous'];
        yield 'controversial' => ['controversial'];
        yield 'insight' => ['insight'];
        yield 'question' => ['question'];
        yield 'other' => ['other'];
    }

    public function testAcceptsFieldsInAnyObjectOrder(): void
    {
        $json = <<<'JSON'
{"clips":[{"category":"other","hook":"Gancho","reason":"Motivo","score":50,"duration":20,"end_time":30,"start_time":10,"title":"Título"}],"video_summary":"Resumo"}
JSON;

        $result = $this->validator->validate($json, 30);

        self::assertSame('Resumo', $result->videoSummary());
        self::assertSame('Título', $result->clips()[0]->title());
    }

    public function testDuplicateJsonKeysUseTheLastDecodedValue(): void
    {
        $json = <<<'JSON'
{"video_summary":"ignorado","video_summary":"Resumo final","clips":[{"title":"Título","start_time":10,"end_time":30,"duration":20,"score":"ignorado","score":92,"reason":"Motivo","hook":"Gancho","category":"educational"}]}
JSON;

        $result = $this->validator->validate($json, 180);

        self::assertSame('Resumo final', $result->videoSummary());
        self::assertSame(92, $result->clips()[0]->score());
    }

    public function testAcceptsInclusiveDurationDriftOfTwoHundredFiftyMilliseconds(): void
    {
        $json = $this->validJson([
            'start_time' => 10.0,
            'end_time' => 30.25,
            'duration' => 20.0,
        ]);

        $result = $this->validator->validate($json, 60);

        self::assertSame(30.25, $result->clips()[0]->endTime());
        self::assertSame(20.0, $result->clips()[0]->duration());
    }

    public function testAcceptsAFullSpanClipForASourceShorterThanTwentySeconds(): void
    {
        $json = $this->validJson([
            'start_time' => 0,
            'end_time' => 19,
            'duration' => 18.75,
        ]);

        $result = $this->validator->validate($json, 19);

        self::assertSame(0.0, $result->clips()[0]->startTime());
        self::assertSame(19.0, $result->clips()[0]->endTime());
    }

    public function testAcceptsExactThreeDecimalTimestampsAndSourceEndBoundary(): void
    {
        $json = $this->validJson([
            'start_time' => 86379.875,
            'end_time' => 86400,
            'duration' => 20.125,
        ]);

        $result = $this->validator->validate($json, 86400);

        self::assertSame(86379.875, $result->clips()[0]->startTime());
        self::assertSame(86400.0, $result->clips()[0]->endTime());
    }

    /** @dataProvider invalidResponses */
    public function testRejectsUntrustedResponse(string $json, int $duration, string $reason): void
    {
        try {
            $this->validator->validate($json, $duration);
            self::fail('Invalid AI response accepted.');
        } catch (InvalidAnalysisResponse $exception) {
            self::assertSame($reason, $exception->reasonCode());
            self::assertSame('A resposta da análise de IA é inválida.', $exception->getMessage());
            self::assertStringNotContainsString($json, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public function invalidResponses(): iterable
    {
        $valid = $this->payload();

        yield 'response above one mebibyte' => [str_repeat('a', 1048577), 180, 'response_too_large'];
        yield 'malformed json' => ['{"video_summary":', 180, 'invalid_json'];
        yield 'root list' => ['[]', 180, 'invalid_shape'];
        yield 'root null' => ['null', 180, 'invalid_shape'];
        yield 'root string' => ['"value"', 180, 'invalid_shape'];
        yield 'missing root field' => [$this->json(['video_summary' => 'Resumo']), 180, 'invalid_fields'];
        yield 'unknown root field' => [$this->json($valid + ['extra' => true]), 180, 'invalid_fields'];
        yield 'numeric root object key' => ['{"0":"confusão","video_summary":"Resumo","clips":[' . $this->json($this->clip()) . ']}', 180, 'invalid_fields'];
        yield 'clips object instead of list' => ['{"video_summary":"Resumo","clips":{}}', 180, 'invalid_shape'];
        yield 'numeric-keyed clips object' => ['{"video_summary":"Resumo","clips":{"0":' . $this->json($this->clip()) . '}}', 180, 'invalid_shape'];
        yield 'sparse numeric-keyed clips object' => ['{"video_summary":"Resumo","clips":{"0":' . $this->json($this->clip()) . ',"2":' . $this->json($this->clip(['start_time' => 40, 'end_time' => 60])) . '}}', 180, 'invalid_shape'];
        yield 'clip list member is list' => ['{"video_summary":"Resumo","clips":[[]]}', 180, 'invalid_shape'];
        yield 'clip list member is scalar' => ['{"video_summary":"Resumo","clips":[1]}', 180, 'invalid_shape'];
        yield 'empty clip list' => [$this->json(['video_summary' => 'Resumo', 'clips' => []]), 180, 'invalid_clip_count'];
        yield 'eleven clips' => [$this->json(['video_summary' => 'Resumo', 'clips' => array_fill(0, 11, $this->clip())]), 180, 'invalid_clip_count'];
        yield 'missing clip field' => [$this->json($this->payloadClipWithout('hook')), 180, 'invalid_fields'];
        yield 'unknown clip field' => [$this->json($this->payloadWithClip($this->clip(['extra' => 'x']))), 180, 'invalid_fields'];

        yield 'summary is not string' => [$this->json($this->payload(['video_summary' => 10])), 180, 'invalid_text'];
        yield 'summary empty' => [$this->json($this->payload(['video_summary' => ''])), 180, 'invalid_text'];
        yield 'summary ascii blank' => [$this->json($this->payload(['video_summary' => " \t\n"])), 180, 'invalid_text'];
        yield 'summary unicode blank' => [$this->json($this->payload(['video_summary' => "\u{00A0}\u{2003}"])), 180, 'invalid_text'];
        yield 'summary invisible format characters only' => [$this->json($this->payload(['video_summary' => "\u{200B}\u{2060}"])), 180, 'invalid_text'];
        yield 'summary too long' => [$this->json($this->payload(['video_summary' => str_repeat('a', 2001)])), 180, 'invalid_text'];
        yield 'summary forbidden control' => [$this->json($this->payload(['video_summary' => "bad\x07summary"])), 180, 'invalid_text'];
        yield 'title is not string' => [$this->validJson(['title' => 42]), 180, 'invalid_text'];
        yield 'title unicode blank' => [$this->validJson(['title' => "\u{3000}"]), 180, 'invalid_text'];
        yield 'title invisible format characters only' => [$this->validJson(['title' => "\u{200B}\u{200C}\u{200D}"]), 180, 'invalid_text'];
        yield 'title too long' => [$this->validJson(['title' => str_repeat('a', 181)]), 180, 'invalid_text'];
        yield 'title forbidden control' => [$this->validJson(['title' => "bad\x00title"]), 180, 'invalid_text'];
        yield 'hook too long' => [$this->validJson(['hook' => str_repeat('a', 501)]), 180, 'invalid_text'];
        yield 'reason too long' => [$this->validJson(['reason' => str_repeat('a', 1001)]), 180, 'invalid_text'];
        yield 'blank category is invalid text' => [$this->validJson(['category' => '']), 180, 'invalid_text'];

        yield 'start numeric string' => [$this->validJson(['start_time' => '10']), 180, 'invalid_timestamp'];
        yield 'start boolean' => [$this->validJson(['start_time' => true]), 180, 'invalid_timestamp'];
        yield 'start nan-like string' => [$this->validJson(['start_time' => 'NaN']), 180, 'invalid_timestamp'];
        yield 'start fourth decimal' => [$this->validJson(['start_time' => 10.0001]), 180, 'invalid_timestamp'];
        yield 'start sub-millisecond' => [$this->validJson(['start_time' => 0.0004, 'end_time' => 20.0, 'duration' => 20.0]), 180, 'invalid_timestamp'];
        yield 'negative sub-epsilon start is not repaired to zero' => [$this->validJson(['start_time' => -0.0000000005, 'end_time' => 20, 'duration' => 20]), 180, 'invalid_timestamp'];
        yield 'sub-epsilon window is not repaired to milliseconds' => [$this->validJson(['start_time' => 0.0000000005, 'end_time' => 20.0000000005, 'duration' => 20.0000000005]), 180, 'invalid_timestamp'];
        yield 'end numeric string' => [$this->validJson(['end_time' => '30']), 180, 'invalid_timestamp'];
        yield 'end fourth decimal' => [$this->validJson(['end_time' => 30.0001]), 180, 'invalid_timestamp'];
        yield 'sub-epsilon end is not repaired to a millisecond' => [$this->validJson(['start_time' => 0, 'end_time' => 20.0000000005, 'duration' => 20]), 180, 'invalid_timestamp'];
        yield 'finite timestamp above integer range' => ['{"video_summary":"Resumo seguro","clips":[{"title":"Título","start_time":10,"end_time":1e300,"duration":20,"score":92,"reason":"Motivo","hook":"Gancho","category":"educational"}]}', 180, 'invalid_timestamp'];
        yield 'non finite end' => ['{"video_summary":"Resumo seguro","clips":[{"title":"Título","start_time":10,"end_time":1e309,"duration":20,"score":92,"reason":"Motivo","hook":"Gancho","category":"educational"}]}', 180, 'invalid_timestamp'];
        yield 'duration numeric string' => [$this->validJson(['duration' => '20']), 180, 'invalid_duration'];
        yield 'duration boolean' => [$this->validJson(['duration' => false]), 180, 'invalid_duration'];
        yield 'duration fourth decimal' => [$this->validJson(['duration' => 20.0001]), 180, 'invalid_duration'];
        yield 'sub-epsilon duration is not repaired to a millisecond' => [$this->validJson(['start_time' => 0, 'end_time' => 20, 'duration' => 20.0000000005]), 180, 'invalid_duration'];
        yield 'duration sub-millisecond' => [$this->validJson(['start_time' => 0, 'end_time' => 20, 'duration' => 0.0004]), 180, 'invalid_duration'];
        yield 'non finite duration' => ['{"video_summary":"Resumo seguro","clips":[{"title":"Título","start_time":10,"end_time":30,"duration":1e309,"score":92,"reason":"Motivo","hook":"Gancho","category":"educational"}]}', 180, 'invalid_duration'];
        yield 'big integer start decoded as string' => ['{"video_summary":"Resumo seguro","clips":[{"title":"Título","start_time":9223372036854775808,"end_time":30,"duration":20,"score":92,"reason":"Motivo","hook":"Gancho","category":"educational"}]}', 180, 'invalid_timestamp'];

        yield 'negative start' => [$this->validJson(['start_time' => -1, 'end_time' => 20, 'duration' => 21]), 180, 'invalid_timeline'];
        yield 'equal bounds' => [$this->validJson(['start_time' => 20, 'end_time' => 20, 'duration' => 20]), 180, 'invalid_timeline'];
        yield 'reversed bounds' => [$this->validJson(['start_time' => 30, 'end_time' => 10, 'duration' => 20]), 180, 'invalid_timeline'];
        yield 'end beyond source duration' => [$this->validJson(['start_time' => 160, 'end_time' => 181, 'duration' => 21]), 180, 'invalid_timeline'];
        yield 'zero declared duration' => [$this->validJson(['duration' => 0]), 180, 'invalid_duration'];
        yield 'negative declared duration' => [$this->validJson(['duration' => -20]), 180, 'invalid_duration'];
        yield 'duration mismatch by 251 milliseconds' => [$this->validJson(['start_time' => 10, 'end_time' => 30.251, 'duration' => 20]), 180, 'invalid_timeline'];
        yield 'clip span above ninety seconds' => [$this->validJson(['start_time' => 0, 'end_time' => 90.001, 'duration' => 90.001]), 180, 'invalid_duration'];
        yield 'declared duration above ninety seconds' => [$this->validJson(['start_time' => 0, 'end_time' => 90, 'duration' => 90.25]), 180, 'invalid_duration'];
        yield 'clip below twenty seconds for regular source' => [$this->validJson(['start_time' => 10, 'end_time' => 29.999, 'duration' => 19.999]), 180, 'invalid_duration'];
        yield 'short source clip does not start at zero' => [$this->validJson(['start_time' => 0.001, 'end_time' => 19, 'duration' => 18.999]), 19, 'invalid_timeline'];
        yield 'short source clip does not reach source end' => [$this->validJson(['start_time' => 0, 'end_time' => 18.999, 'duration' => 18.999]), 19, 'invalid_timeline'];

        yield 'score numeric string' => [$this->validJson(['score' => '92']), 180, 'invalid_score'];
        yield 'score boolean' => [$this->validJson(['score' => true]), 180, 'invalid_score'];
        yield 'score fractional' => [$this->validJson(['score' => 92.5]), 180, 'invalid_score'];
        yield 'score below zero' => [$this->validJson(['score' => -1]), 180, 'invalid_score'];
        yield 'score above one hundred' => [$this->validJson(['score' => 101]), 180, 'invalid_score'];
        yield 'non finite score' => ['{"video_summary":"Resumo seguro","clips":[{"title":"Título","start_time":10,"end_time":30,"duration":20,"score":1e309,"reason":"Motivo","hook":"Gancho","category":"educational"}]}', 180, 'invalid_score'];
        yield 'score bigint string after decode' => ['{"video_summary":"Resumo seguro","clips":[{"title":"Título","start_time":10,"end_time":30,"duration":20,"score":9223372036854775808,"reason":"Motivo","hook":"Gancho","category":"educational"}]}', 180, 'invalid_score'];
        yield 'unsupported category' => [$this->validJson(['category' => 'viral']), 180, 'invalid_category'];

        $duplicate = $this->clip();
        yield 'exact duplicate intervals' => [$this->json(['video_summary' => 'Resumo seguro', 'clips' => [$duplicate, $duplicate]]), 180, 'duplicate_clip'];
    }

    /** @dataProvider invalidSourceDurations */
    public function testRejectsInvalidSourceDurationAsProgrammerMisuse(int $duration): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validate($this->validJson(), $duration);
    }

    /** @return iterable<string, array{int}> */
    public function invalidSourceDurations(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above supported day' => [86401];
    }

    public function testExceptionAcceptsOnlyAllowlistedReasonsAndAlwaysUsesFixedMessage(): void
    {
        $reasons = [
            'response_too_large',
            'invalid_json',
            'invalid_shape',
            'invalid_fields',
            'invalid_clip_count',
            'invalid_text',
            'invalid_timestamp',
            'invalid_duration',
            'invalid_timeline',
            'invalid_score',
            'invalid_category',
            'duplicate_clip',
            'invalid_domain',
        ];

        foreach ($reasons as $reason) {
            $exception = new InvalidAnalysisResponse($reason);
            self::assertSame($reason, $exception->reasonCode());
            self::assertSame('A resposta da análise de IA é inválida.', $exception->getMessage());
        }
    }

    public function testExceptionRejectsUnknownReason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InvalidAnalysisResponse('payload:' . $this->validJson());
    }

    /** @param array<string, mixed> $rootOverrides */
    private function payload(array $rootOverrides = []): array
    {
        return array_replace([
            'video_summary' => 'Resumo seguro',
            'clips' => [$this->clip()],
        ], $rootOverrides);
    }

    /** @param array<string, mixed> $overrides */
    private function clip(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Título',
            'start_time' => 10,
            'end_time' => 30,
            'duration' => 20,
            'score' => 92,
            'reason' => 'Motivo',
            'hook' => 'Gancho',
            'category' => 'educational',
        ], $overrides);
    }

    /** @param array<string, mixed> $clip */
    private function payloadWithClip(array $clip): array
    {
        return ['video_summary' => 'Resumo seguro', 'clips' => [$clip]];
    }

    /** @return array<string, mixed> */
    private function payloadClipWithout(string $field): array
    {
        $clip = $this->clip();
        unset($clip[$field]);

        return $this->payloadWithClip($clip);
    }

    /** @param array<string, mixed> $clipOverrides */
    private function validJson(array $clipOverrides = []): string
    {
        return $this->json($this->payloadWithClip($this->clip($clipOverrides)));
    }

    /** @param array<string, mixed> $value */
    private function json(array $value, int $flags = 0): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | $flags);
    }
}
