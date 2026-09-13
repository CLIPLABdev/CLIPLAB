<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use PHPUnit\Framework\TestCase;

final class StreamedResponseTest extends TestCase
{
    public function testStreamEmitsItsChunksOnceAndKeepsAnEmptyBody(): void
    {
        $emissions = 0;
        $response = Response::stream(static function () use (&$emissions): void {
            ++$emissions;
            echo 'part-1';
            echo 'part-2';
        })->withHeader('Content-Type', 'application/octet-stream');

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        self::assertSame('part-1part-2', $output);
        self::assertSame(1, $emissions);
        self::assertSame('', $response->body());
        self::assertSame('application/octet-stream', $response->header('Content-Type'));
    }

    public function testLegacyHtmlAndJsonResponsesKeepTheirBodies(): void
    {
        $html = Response::html('<main>clip</main>');
        $json = Response::json(['status' => 'queued']);

        self::assertSame('<main>clip</main>', $html->body());
        self::assertSame('text/html; charset=UTF-8', $html->header('Content-Type'));
        self::assertSame('{"status":"queued"}', $json->body());
        self::assertSame('application/json; charset=UTF-8', $json->header('Content-Type'));
    }
}
