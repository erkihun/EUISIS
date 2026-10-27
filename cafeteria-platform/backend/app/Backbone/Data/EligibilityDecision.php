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
        public ?string $message = null,
        public ?string $messageKey = null,
    ) {}

    /** @param  array{eligible: bool, reason_code: string|null, card_status: string|null, message?: string|null, message_key?: string|null}  $result */
    public static function fromArray(array $result): self
    {
        return new self($result['eligible'], $result['reason_code'], $result['card_status'], $result['message'] ?? null, $result['message_key'] ?? null);
    }
}
