<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services\Boarding;

use Modules\Transport\Domain\Models\TransportTransaction;

/**
 * The engine's answer. `resultCode` always has a message in
 * `transport.reasons.*`; `replayed` marks an idempotent repeat.
 */
final readonly class BoardingDecision
{
    public function __construct(
        public bool $accepted,
        public string $resultCode,
        public ?TransportTransaction $transaction = null,
        public bool $replayed = false,
        /** Operational notes the operator should see (overcapacity warning, …). */
        public array $warnings = [],
    ) {}

    public static function refuse(string $code, ?TransportTransaction $transaction = null): self
    {
        return new self(false, $code, $transaction);
    }

    public function messageKey(): string
    {
        return $this->replayed ? 'transport.duplicate_scan' : ($this->accepted ? 'transport.scan_accepted' : 'transport.scan_rejected');
    }

    /** The legacy array shape the portal and admin terminals were built on. @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string, warnings: array} */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'result_code' => $this->resultCode,
            'transaction' => $this->transaction,
            'message_key' => $this->messageKey(),
            'warnings' => $this->warnings,
        ];
    }
}
