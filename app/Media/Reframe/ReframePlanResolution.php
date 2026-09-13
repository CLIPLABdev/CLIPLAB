<?php

declare(strict_types=1);

namespace App\Media\Reframe;

final class ReframePlanResolution
{
    private string $state;
    private ?ReframePlan $plan;

    private function __construct(string $state, ?ReframePlan $plan)
    {
        $this->state = $state;
        $this->plan = $plan;
    }

    public static function matched(ReframePlan $plan): self
    {
        return new self('matched', $plan);
    }

    public static function legacy(): self
    {
        return new self('legacy', null);
    }

    public static function mismatch(): self
    {
        return new self('mismatch', null);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function plan(): ?ReframePlan
    {
        return $this->plan;
    }
}
