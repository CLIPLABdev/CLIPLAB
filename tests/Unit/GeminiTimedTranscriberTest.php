<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GeminiTimedTranscriber;
use App\Gemini\GeminiTransport;
use App\Gemini\GeminiHttpResponse;
use App\Gemini\GeminiException;
use PHPUnit\Framework\TestCase;

final class GeminiTimedTranscriberTest extends TestCase
{
    private function wav(): string { return 'RIFF' . pack('V', 36 + 32000) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', 32000) . str_repeat("\0", 32000); }

    public function testSendsOnlyInlineAudioAndValidatesRelativeTimestamps(): void
    {
        $transport = new SubtitleTransport();
        $service = new GeminiTimedTranscriber($transport, 'test-secret', 'gemini-test', 30, 65536);
        $track = $service->transcribe($this->wav(), 1000);
        self::assertSame('Oi', $track->cues()[0]->text());
        self::assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertStringNotContainsString('test-secret', $request['url']);
        self::assertSame('test-secret', $request['headers']['x-goog-api-key']);
        $body = json_decode($request['body'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('audio/wav', $body['contents'][0]['parts'][0]['inlineData']['mimeType']);
        self::assertSame($this->wav(), base64_decode($body['contents'][0]['parts'][0]['inlineData']['data'], true));
        self::assertSame('application/json', $body['generationConfig']['responseMimeType']);
        self::assertIsArray($body['generationConfig']['responseSchema']);
        self::assertArrayNotHasKey('responseFormat', $body['generationConfig']);
        self::assertArrayNotHasKey('additionalProperties', $body['generationConfig']['responseSchema']);
        self::assertSame('OBJECT', $body['generationConfig']['responseSchema']['type']);
        self::assertSame('ARRAY', $body['generationConfig']['responseSchema']['properties']['cues']['type']);
        self::assertSame('STRING', $body['generationConfig']['responseSchema']['properties']['cues']['items']['properties']['text']['type']);
        self::assertArrayNotHasKey('additionalProperties', $body['generationConfig']['responseSchema']['properties']['cues']['items']);
        self::assertArrayNotHasKey('additionalProperties', $body['generationConfig']['responseSchema']['properties']['cues']['items']['properties']['words']['items']);
        self::assertStringContainsString('1000', $body['contents'][0]['parts'][1]['text']);
    }

    public function testProviderSchemaKeepsTranscriptShapeAndScalarBoundsWithoutHighCardinalityArrayCaps(): void
    {
        $transport = new SubtitleTransport();
        (new GeminiTimedTranscriber($transport, 'test-secret', 'gemini-test', 30, 65536))
            ->transcribe($this->wav(), 1000);

        $body = json_decode($transport->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);
        $schema = $body['generationConfig']['responseSchema'];
        $cues = $schema['properties']['cues'];
        $cue = $cues['items'];
        $words = $cue['properties']['words'];
        $word = $words['items'];

        self::assertSame(['language', 'cues'], $schema['required']);
        self::assertSame('STRING', $schema['properties']['language']['type']);
        self::assertSame('ARRAY', $cues['type']);
        self::assertArrayNotHasKey('maxItems', $cues);
        self::assertSame(['start_ms', 'end_ms', 'text'], $cue['required']);
        self::assertSame(['type' => 'INTEGER', 'minimum' => 0, 'maximum' => 1000], $cue['properties']['start_ms']);
        self::assertSame(['type' => 'INTEGER', 'minimum' => 1, 'maximum' => 1000], $cue['properties']['end_ms']);
        self::assertSame(['type' => 'STRING', 'minLength' => 1, 'maxLength' => 350], $cue['properties']['text']);
        self::assertSame('ARRAY', $words['type']);
        self::assertArrayNotHasKey('maxItems', $words);
        self::assertSame(['start_ms', 'end_ms', 'text'], $word['required']);
        self::assertSame(['type' => 'INTEGER', 'minimum' => 0, 'maximum' => 1000], $word['properties']['start_ms']);
        self::assertSame(['type' => 'INTEGER', 'minimum' => 1, 'maximum' => 1000], $word['properties']['end_ms']);
        self::assertSame(['type' => 'STRING', 'minLength' => 1, 'maxLength' => 80], $word['properties']['text']);
    }

    /** @dataProvider failures */
    public function testMapsFailureWithoutLeakingProviderContent(int $status, string $body, string $code): void
    {
        $transport = new SubtitleTransport();
        $transport->status = $status;
        $transport->body = $body;
        try {
            (new GeminiTimedTranscriber($transport,'test-secret','gemini-test',30,65536))->transcribe($this->wav(),1000);
            self::fail('Expected safe failure.');
        } catch (GeminiException $error) {
            self::assertSame($code,$error->publicCode());
            self::assertStringNotContainsString('private-data',$error->getMessage());
            self::assertStringNotContainsString('test-secret',$error->getMessage());
        }
    }

    public function failures(): iterable
    {
        yield [429,'private-data','ai_rate_limited'];
        yield [503,'private-data','ai_unavailable'];
        yield [401,'private-data','ai_provider_rejected'];
        yield [200,'private-data','ai_provider_rejected'];
        yield [200,'{"candidates":[{"finishReason":"MAX_TOKENS","content":{"parts":[{"text":"private-data"}]}}]}','ai_provider_rejected'];
        yield [200,'{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"{\"language\":\"pt\",\"cues\":[{\"start_ms\":0,\"end_ms\":1001,\"text\":\"private-data\"}]}"}]}}]}','ai_provider_rejected'];
    }

    public function testRejectsInvalidAudioBeforeNetwork(): void
    {
        $transport = new SubtitleTransport();
        try {
            (new GeminiTimedTranscriber($transport,'test-secret','gemini-test',30,65536))->transcribe('not wav',1000);
            self::fail('Expected rejection.');
        } catch (GeminiException $error) {
            self::assertSame('ai_provider_rejected',$error->publicCode());
            self::assertSame([],$transport->requests);
        }
    }

    public function testMissingConfigurationDoesNotCallProvider(): void
    {
        $transport = new SubtitleTransport();
        try {
            (new GeminiTimedTranscriber($transport,'','',30,65536))->transcribe($this->wav(),1000);
            self::fail('Expected rejection.');
        } catch (GeminiException $error) {
            self::assertSame('ai_unconfigured',$error->publicCode());
            self::assertSame([],$transport->requests);
        }
    }
}

final class SubtitleTransport implements GeminiTransport
{
    public array $requests = [];
    public int $status = 200;
    public ?string $body = null;
    public function request(string $method,string $url,array $headers,?string $body,int $timeoutSeconds,int $responseLimitBytes): GeminiHttpResponse
    {
        $this->requests[] = compact('method','url','headers','body');
        $text = json_encode(['language'=>'pt','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'Oi']]]);
        return new GeminiHttpResponse($this->status,[], $this->body ?? json_encode(['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>$text]]]]]]));
    }
    public function upload(string $url,array $headers,string $absolutePath,int $sizeBytes,int $timeoutSeconds,int $responseLimitBytes): GeminiHttpResponse
    {
        throw new \LogicException('Inline audio must not use remote file upload.');
    }
}
