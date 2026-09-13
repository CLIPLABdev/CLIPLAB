<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Gemini\CurlGeminiTransport;
use App\Gemini\GeminiException;
use App\Services\GeminiTimedTranscriber;
use PHPUnit\Framework\TestCase;

final class CurlGeminiTransportTest extends TestCase
{
    private string $directory;
    private string $fixture;

    protected function setUp(): void
    {
        if (!defined('CURLOPT_UPLOAD')) {
            self::markTestSkipped('The cURL extension is not available.');
        }
        $this->directory = sys_get_temp_dir() . '/curl-gemini-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->fixture = $this->directory . '/video.mp4';
        file_put_contents($this->fixture, str_repeat('0123456789', 10));
    }

    protected function tearDown(): void
    {
        @unlink($this->fixture);
        @rmdir($this->directory);
    }

    public function testRequestUsesSecureBoundedOptionsAndParsesTheFinalHeaderBlock(): void
    {
        $curl = new FakeGeminiCurlOperations();
        $curl->headerLines = [
            "HTTP/1.1 100 Continue\r\n",
            "X-Interim: ignored\r\n",
            "\r\n",
            "HTTP/2 200 OK\r\n",
            "X-Request-Id: first\r\n",
            "x-request-id: second\r\n",
            "Content-Type: application/json\r\n",
            "\r\n",
        ];
        $curl->bodyChunks = ['{"ok":', 'true}'];
        $transport = new CurlGeminiTransport($curl);

        $response = $transport->request(
            'POST',
            'https://generativelanguage.googleapis.com/v1beta/models/model:generateContent',
            ['Content-Type' => 'application/json', 'x-goog-api-key' => 'opaque-key'],
            '{"request":true}',
            17,
            1024
        );

        self::assertSame(200, $response->status());
        self::assertSame('{"ok":true}', $response->body());
        self::assertNull($response->header('x-interim'));
        self::assertSame(['first', 'second'], $response->headerValues('X-Request-ID'));
        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame('POST', $curl->options[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('{"request":true}', $curl->options[CURLOPT_POSTFIELDS]);
        self::assertSame(['Content-Type: application/json', 'x-goog-api-key: opaque-key'], $curl->options[CURLOPT_HTTPHEADER]);
        self::assertFalse($curl->options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $curl->options[CURLOPT_MAXREDIRS]);
        self::assertSame('', $curl->options[CURLOPT_PROXY]);
        self::assertSame('*', $curl->options[CURLOPT_NOPROXY]);
        self::assertFalse($curl->options[CURLOPT_HTTPPROXYTUNNEL]);
        self::assertTrue($curl->options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $curl->options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(CURLPROTO_HTTPS, $curl->options[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $curl->options[CURLOPT_REDIR_PROTOCOLS]);
        self::assertFalse($curl->options[CURLOPT_VERBOSE]);
        self::assertFalse($curl->options[CURLOPT_RETURNTRANSFER]);
        self::assertSame(17, $curl->options[CURLOPT_TIMEOUT]);
        self::assertGreaterThanOrEqual(1, $curl->options[CURLOPT_CONNECTTIMEOUT]);
        self::assertLessThanOrEqual(17, $curl->options[CURLOPT_CONNECTTIMEOUT]);
        self::assertTrue($curl->closed);
        self::assertSame(1, $curl->closeCalls);
    }

    public function testTimedTranscriberCanSendMaximumDurationInlineAudioWithASmallerResponseCap(): void
    {
        $curl = new FakeGeminiCurlOperations();
        $transcriptJson = json_encode(['language' => 'und', 'cues' => []], JSON_THROW_ON_ERROR);
        $curl->bodyChunks = [json_encode([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $transcriptJson]]],
            ]],
        ], JSON_THROW_ON_ERROR)];
        $executed = false;
        $curl->beforeExecute = static function () use (&$executed): void {
            $executed = true;
        };
        $transcriber = new GeminiTimedTranscriber(
            new CurlGeminiTransport($curl),
            'opaque-key',
            'gemini-test',
            30,
            1048576
        );
        $sampleBytes = 180000 * 32;
        $wav = 'RIFF' . pack('V', 36 + $sampleBytes) . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            . 'data' . pack('V', $sampleBytes) . str_repeat("\0", $sampleBytes);

        try {
            $transcript = $transcriber->transcribe($wav, 180000);
        } finally {
            self::assertTrue($executed, 'Maximum accepted inline audio must reach the cURL execution boundary.');
        }

        self::assertSame(180000, $transcript->durationMs());
        self::assertSame('und', $transcript->language());
        self::assertSame([], $transcript->cues());
    }

    public function testGetAndDeleteUseOnlyTheirExplicitMethodsWithoutRequestBodies(): void
    {
        foreach (['GET', 'DELETE'] as $method) {
            $curl = new FakeGeminiCurlOperations();
            $transport = new CurlGeminiTransport($curl);

            $transport->request(
                $method,
                'https://generativelanguage.googleapis.com/v1beta/files/a',
                [],
                null,
                5,
                128
            );

            self::assertSame($method, $curl->options[CURLOPT_CUSTOMREQUEST]);
            self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $curl->options);
            self::assertTrue($curl->closed);
        }
    }

    public function testUploadOpensAReadStreamUsesExactSizeAndExplicitPost(): void
    {
        $curl = new FakeGeminiCurlOperations();
        $curl->bodyChunks = ['{"file":{}}'];
        $transport = new CurlGeminiTransport($curl);

        $response = $transport->upload(
            'https://generativelanguage.googleapis.com/upload/session?opaque=1',
            ['Content-Type' => 'video/mp4', 'Content-Length' => (string) filesize($this->fixture)],
            $this->fixture,
            (int) filesize($this->fixture),
            30,
            4096
        );

        self::assertSame('{"file":{}}', $response->body());
        self::assertTrue($curl->options[CURLOPT_UPLOAD]);
        self::assertSame('POST', $curl->options[CURLOPT_CUSTOMREQUEST]);
        self::assertTrue($curl->streamWasOpenDuringExecute);
        self::assertSame(filesize($this->fixture), $curl->options[CURLOPT_INFILESIZE]);
        self::assertSame(filesize($this->fixture), $curl->uploadedBytes);
        self::assertFalse(is_resource($curl->inputStream));
        self::assertTrue($curl->closed);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $curl->options);
    }

    /** @dataProvider invalidMethodsAndUrls */
    public function testRejectsUnsupportedMethodOrUrlBeforeOpeningCurl(string $method, string $url): void
    {
        $curl = new FakeGeminiCurlOperations();
        $transport = new CurlGeminiTransport($curl);

        $this->expectCode('ai_provider_rejected', false, function () use ($transport, $method, $url): void {
            $transport->request($method, $url, [], null, 5, 128);
        });
        self::assertSame(0, $curl->initCalls);
    }

    /** @return iterable<string, array{string, string}> */
    public function invalidMethodsAndUrls(): iterable
    {
        yield 'unsupported put' => ['PUT', 'https://generativelanguage.googleapis.com/v1beta/files/a'];
        yield 'lowercase method' => ['get', 'https://generativelanguage.googleapis.com/v1beta/files/a'];
        yield 'plain http' => ['GET', 'http://generativelanguage.googleapis.com/v1beta/files/a'];
        yield 'foreign host' => ['GET', 'https://example.test/v1beta/files/a'];
        yield 'lookalike host' => ['GET', 'https://generativelanguage.googleapis.com.example.test/v1beta/files/a'];
        yield 'trailing host dot' => ['GET', 'https://generativelanguage.googleapis.com./v1beta/files/a'];
        yield 'userinfo' => ['GET', 'https://u:p@generativelanguage.googleapis.com/v1beta/files/a'];
        yield 'fragment' => ['GET', 'https://generativelanguage.googleapis.com/v1beta/files/a#fragment'];
        yield 'wrong port' => ['GET', 'https://generativelanguage.googleapis.com:444/v1beta/files/a'];
        yield 'query on fixed API request' => ['GET', 'https://generativelanguage.googleapis.com/v1beta/files/a?key=secret'];
        yield 'header injection in url' => ['GET', "https://generativelanguage.googleapis.com/v1beta/files/a\r\nX: y"];
    }

    /** @dataProvider invalidLimitsAndHeaders */
    public function testRejectsInvalidLimitsOrHeadersWithoutOpeningCurl(array $arguments): void
    {
        $curl = new FakeGeminiCurlOperations();
        $transport = new CurlGeminiTransport($curl);

        $this->expectCode('ai_provider_rejected', false, function () use ($transport, $arguments): void {
            $transport->request(
                'POST',
                'https://generativelanguage.googleapis.com/v1beta/files',
                $arguments['headers'],
                $arguments['body'],
                $arguments['timeout'],
                $arguments['limit']
            );
        });
        self::assertSame(0, $curl->initCalls);
    }

    /** @return iterable<string, array{array{headers: array<mixed>, body: ?string, timeout: int, limit: int}}> */
    public function invalidLimitsAndHeaders(): iterable
    {
        yield 'zero timeout' => [['headers' => [], 'body' => null, 'timeout' => 0, 'limit' => 100]];
        yield 'zero response limit' => [['headers' => [], 'body' => null, 'timeout' => 5, 'limit' => 0]];
        yield 'header name injection' => [['headers' => ["X-Test\r\nBad" => 'v'], 'body' => null, 'timeout' => 5, 'limit' => 100]];
        yield 'header value injection' => [['headers' => ['X-Test' => "v\nBad: 1"], 'body' => null, 'timeout' => 5, 'limit' => 100]];
        yield 'numeric header name' => [['headers' => [0 => 'X-Test: value'], 'body' => null, 'timeout' => 5, 'limit' => 100]];
    }

    public function testRejectsRequestBodyAboveTheOutboundCapBeforeOpeningCurl(): void
    {
        $curl = new FakeGeminiCurlOperations();
        $transport = new CurlGeminiTransport($curl);

        $this->expectCode('ai_provider_rejected', false, function () use ($transport): void {
            $transport->request(
                'POST',
                'https://generativelanguage.googleapis.com/v1beta/models/model:generateContent',
                ['Content-Type' => 'application/json'],
                str_repeat('x', 10485761),
                5,
                128
            );
        });
        self::assertSame(0, $curl->initCalls);
    }

    public function testResponseBodyIsCappedIncrementallyAndHandleAlwaysCloses(): void
    {
        $curl = new FakeGeminiCurlOperations();
        $curl->bodyChunks = ['1234', '5'];
        $transport = new CurlGeminiTransport($curl);

        $this->expectCode('ai_provider_rejected', false, function () use ($transport): void {
            $transport->request('GET', 'https://generativelanguage.googleapis.com/v1beta/files/a', [], null, 5, 4);
        });
        self::assertTrue($curl->closed);
        self::assertSame(1, $curl->closeCalls);
    }

    public function testResponseHeadersAreCappedAcrossInterimAndFinalBlocks(): void
    {
        $curl = new FakeGeminiCurlOperations();
        $curl->headerLines = [
            "HTTP/1.1 100 Continue\r\n",
            "X-Long: 1234567890\r\n",
            "\r\n",
            "HTTP/2 200 OK\r\n",
        ];
        $transport = new CurlGeminiTransport($curl);

        $this->expectCode('ai_provider_rejected', false, function () use ($transport): void {
            $transport->request('GET', 'https://generativelanguage.googleapis.com/v1beta/files/a', [], null, 5, 30);
        });
        self::assertTrue($curl->closed);
    }

    /** @dataProvider curlFailures */
    public function testMapsCurlFailuresToSanitizedTaxonomy(int $errno, string $code, bool $transient): void
    {
        $curl = new FakeGeminiCurlOperations();
        $curl->execResult = false;
        $curl->errno = $errno;
        $transport = new CurlGeminiTransport($curl);

        try {
            $transport->request('GET', 'https://generativelanguage.googleapis.com/v1beta/files/a', [], null, 5, 128);
            self::fail('Expected transport failure.');
        } catch (GeminiException $exception) {
            self::assertSame($code,$exception->publicCode());
            self::assertSame($transient,$exception->isTransient());
            self::assertSame(['failure_kind'=>'transport','curl_errno'=>$errno],$exception->safeDiagnostic());
        }
        self::assertTrue($curl->closed);
    }

    /** @return iterable<string, array{int, string, bool}> */
    public function curlFailures(): iterable
    {
        yield 'timeout' => [CURLE_OPERATION_TIMEDOUT, 'ai_timeout', true];
        yield 'network' => [CURLE_COULDNT_CONNECT, 'ai_unavailable', true];
        yield 'read failure' => [CURLE_READ_ERROR, 'ai_unavailable', true];
    }

    public function testCallbackOrDriverExceptionIsSanitizedAndClosesHandle(): void
    {
        $curl = new FakeGeminiCurlOperations();
        $curl->throwOnExecute = new \RuntimeException('curl diagnostic https://secret-session/?key=secret');
        $transport = new CurlGeminiTransport($curl);

        try {
            $transport->request('GET', 'https://generativelanguage.googleapis.com/v1beta/files/a', [], null, 5, 128);
            self::fail('Driver exception was not sanitized.');
        } catch (GeminiException $exception) {
            self::assertSame('ai_unavailable', $exception->publicCode());
            self::assertStringNotContainsString('diagnostic', $exception->getMessage());
            self::assertStringNotContainsString('secret-session', $exception->getMessage());
            self::assertSame(['failure_kind'=>'internal'],$exception->safeDiagnostic());
            self::assertNull($exception->getPrevious());
        }
        self::assertTrue($curl->closed);
    }

    public function testFailedOptionSetupStillClosesHandle(): void
    {
        $curl = new FakeGeminiCurlOperations();
        $curl->setOptionsResult = false;
        $transport = new CurlGeminiTransport($curl);

        $this->expectCode('ai_unavailable', true, function () use ($transport): void {
            $transport->request('GET', 'https://generativelanguage.googleapis.com/v1beta/files/a', [], null, 5, 128);
        });
        self::assertTrue($curl->closed);
        self::assertSame(1, $curl->closeCalls);
    }

    public function testUploadRejectsNonRegularUnreadableOrSizeMismatchedFilesBeforeCurl(): void
    {
        foreach ([
            [$this->directory, 1],
            [$this->fixture, (int) filesize($this->fixture) + 1],
            ['relative/video.mp4', 1],
        ] as [$path, $size]) {
            $curl = new FakeGeminiCurlOperations();
            $transport = new CurlGeminiTransport($curl);
            try {
                $transport->upload(
                    'https://generativelanguage.googleapis.com/upload/session',
                    [],
                    $path,
                    $size,
                    5,
                    128
                );
                self::fail('Invalid upload source was accepted.');
            } catch (GeminiException $exception) {
                self::assertSame('ai_provider_rejected', $exception->publicCode());
            }
            self::assertSame(0, $curl->initCalls);
        }
    }

    public function testUploadDetectsAShortReadAndClosesBothResources(): void
    {
        $originalSize = (int) filesize($this->fixture);
        $curl = new FakeGeminiCurlOperations();
        $curl->beforeExecute = function (): void {
            file_put_contents($this->fixture, 'short');
        };
        $transport = new CurlGeminiTransport($curl);

        $this->expectCode('ai_provider_rejected', false, function () use ($transport, $originalSize): void {
            $transport->upload(
                'https://generativelanguage.googleapis.com/upload/session',
                [],
                $this->fixture,
                $originalSize,
                5,
                128
            );
        });
        self::assertTrue($curl->closed);
        self::assertFalse(is_resource($curl->inputStream));
        self::assertLessThan($originalSize, $curl->uploadedBytes);
    }

    private function expectCode(string $code, bool $transient, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected GeminiException was not thrown.');
        } catch (GeminiException $exception) {
            self::assertSame($code, $exception->publicCode());
            self::assertSame($transient, $exception->isTransient());
        }
    }
}

final class FakeGeminiCurlOperations
{
    /** @var array<int, mixed> */
    public array $options = [];
    /** @var list<string> */
    public array $headerLines = ["HTTP/2 200 OK\r\n", "\r\n"];
    /** @var list<string> */
    public array $bodyChunks = [];
    public bool $setOptionsResult = true;
    /** @var bool */
    public $execResult = true;
    public int $status = 200;
    public int $errno = 0;
    public bool $closed = false;
    public int $closeCalls = 0;
    public int $initCalls = 0;
    public bool $streamWasOpenDuringExecute = false;
    /** @var resource|null */
    public $inputStream = null;
    public int $uploadedBytes = 0;
    public ?\Throwable $throwOnExecute = null;
    /** @var callable|null */
    public $beforeExecute = null;
    public bool $available = true;
    public bool $initResult = true;

    public function available(): bool
    {
        return $this->available;
    }

    public function init(string $url)
    {
        $this->initCalls++;

        return $this->initResult ? (object) ['url' => $url] : false;
    }

    /** @param array<int, mixed> $options */
    public function setOptions($handle, array $options): bool
    {
        $this->options = $options;
        $this->inputStream = $options[CURLOPT_INFILE] ?? null;

        return $this->setOptionsResult;
    }

    public function execute($handle)
    {
        if ($this->beforeExecute !== null) {
            ($this->beforeExecute)();
        }
        $this->streamWasOpenDuringExecute = is_resource($this->inputStream);
        if ($this->throwOnExecute instanceof \Throwable) {
            throw $this->throwOnExecute;
        }

        if (isset($this->options[CURLOPT_READFUNCTION])) {
            $iterations = 0;
            do {
                $chunk = ($this->options[CURLOPT_READFUNCTION])($handle, $this->inputStream, 7);
                if (!is_string($chunk)) {
                    return false;
                }
                $this->uploadedBytes += strlen($chunk);
                $iterations++;
                if ($iterations > 10000) {
                    throw new \RuntimeException('Fake read loop did not terminate.');
                }
            } while ($chunk !== '');
        }

        foreach ($this->headerLines as $line) {
            $written = ($this->options[CURLOPT_HEADERFUNCTION])($handle, $line);
            if ($written !== strlen($line)) {
                return false;
            }
        }
        foreach ($this->bodyChunks as $chunk) {
            $written = ($this->options[CURLOPT_WRITEFUNCTION])($handle, $chunk);
            if ($written !== strlen($chunk)) {
                return false;
            }
        }

        return $this->execResult;
    }

    public function status($handle): int
    {
        return $this->status;
    }

    public function errno($handle): int
    {
        return $this->errno;
    }

    public function close($handle): void
    {
        $this->closed = true;
        $this->closeCalls++;
    }
}
