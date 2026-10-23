<?php

declare(strict_types=1);

namespace App\Backbone\Data;

/** Whether an employee may receive a service now, and why not when refused. */
final readonly class EligibilityDecision
{
    public function __construct(
        public bool $eligible,
        public ?string $reasonCode,
        public ?string $cardStatus,
    ) {}
}
