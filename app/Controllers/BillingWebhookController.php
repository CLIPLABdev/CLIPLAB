<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request,Response};
use DomainException;
use Throwable;

final class BillingWebhookController
{
    /** @var callable(string,string,string,string):array{status:string} */ private $process;
    /** @var callable(string,string):bool */ private $limit;
    public function __construct(callable $process, callable $limit) { $this->process = $process; $this->limit = $limit; }
    public function stripe(Request $request, array $parameters): Response { return $this->receive($request, (string) ($parameters['environment'] ?? ''), (string) $request->header('Stripe-Signature', '')); }
    public function pagarme(Request $request, array $parameters): Response { return $this->receive($request, (string) ($parameters['environment'] ?? ''), (string) ($parameters['token'] ?? ''), 'pagarme'); }
    private function receive(Request $request, string $environment, string $authentication, string $provider = 'stripe'): Response
    {
        if (strlen($request->rawBody()) > 262144) return Response::text('Payload too large', 413);
        try {
            if (!(($this->limit)($provider, $request->clientIp()))) return Response::text('Too many requests', 429);
            $result = ($this->process)($provider, $environment, $request->rawBody(), $authentication);
            return match ($result['status'] ?? '') { 'processed', 'duplicate', 'ignored' => Response::text('accepted', 200), 'retry' => Response::text('temporarily unavailable', 503), 'rejected' => Response::text('rejected', 400), default => Response::text('rejected', 400) };
        } catch (DomainException) { return Response::text('rejected', 400); } catch (Throwable) { return Response::text('temporarily unavailable', 503); }
    }
}
