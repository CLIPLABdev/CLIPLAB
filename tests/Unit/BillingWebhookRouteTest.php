<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class BillingWebhookRouteTest extends TestCase
{
    public function testSignedWebhookRouteReceivesUnchangedRawBodyWithoutCsrfSession(): void
    {
        if (!method_exists(Router::class, 'webhookPost') || !method_exists(Request::class, 'fakeRaw')) {
            self::fail('Billing webhook primitives are not available.');
        }

        $router = new Router();
        $received = '';
        $signature = '';
        $router->webhookPost('/webhooks/provider', static function (Request $request) use (&$received, &$signature): Response {
            $received = $request->rawBody();
            $signature = (string) $request->header('X-Provider-Signature');

            return Response::text('accepted', 202);
        });

        $response = $router->dispatch(Request::fakeRaw(
            'POST',
            '/webhooks/provider',
            '{"event":"payment.paid","id":"evt_42"}',
            ['X-Provider-Signature' => 'sig_42', 'Content-Type' => 'application/json']
        ));

        self::assertSame(202, $response->status());
        self::assertSame('{"event":"payment.paid","id":"evt_42"}', $received);
        self::assertSame('sig_42', $signature);
    }

    public function testOrdinaryPostRoutesStillRequireCsrf(): void
    {
        $router = new Router();
        $router->post('/ordinary-post', static fn (): Response => Response::text('unsafe'));

        self::assertSame(419, $router->dispatch(Request::fake('POST', '/ordinary-post'))->status());
    }
}
