<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\ErrorHandler;
use App\Core\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorPageTest extends TestCase
{
    private string $directory;
    private string $logFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cliplab-errors-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->directory));
        $this->logFile = $this->directory . '/app.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testProductionExceptionPageHidesDetailsAndIncludesCorrelationId(): void
    {
        $handler = new ErrorHandler(new Logger($this->logFile));
        $exception = new RuntimeException('database password is secret', 0);

        $response = $handler->renderException($exception, false);

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Ocorreu um erro inesperado.', $response->body());
        self::assertStringContainsString('Código de referência:', $response->body());
        self::assertStringNotContainsString('database password is secret', $response->body());
        self::assertStringNotContainsString($exception->getFile(), $response->body());
        self::assertStringContainsString('database password is secret', (string) file_get_contents($this->logFile));
    }

    /** @dataProvider clientErrorStatuses */
    public function testRendersDedicatedSafePageForClientErrors(int $status, string $expectedText): void
    {
        $response = (new ErrorHandler(new Logger($this->logFile)))->renderStatus($status);

        self::assertSame($status, $response->status());
        self::assertStringContainsString($expectedText, $response->body());
    }

    /** @return array<string, array{int, string}> */
    public function clientErrorStatuses(): array
    {
        return [
            'not found' => [404, 'Página não encontrada'],
            'expired session' => [419, 'Sua sessão expirou'],
            'too many requests' => [429, 'Muitas tentativas'],
        ];
    }
}
