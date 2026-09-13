<?php

declare(strict_types=1);

namespace App\Contracts;

interface Mailer
{
    public function send(string $recipient, string $subject, string $html): void;
}
