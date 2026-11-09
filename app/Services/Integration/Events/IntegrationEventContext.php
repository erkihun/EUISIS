<?php

declare(strict_types=1);

namespace App\Services\Integration\Events;

use Closure;
use Illuminate\Support\Str;

/**
 * Per-operation context for recorded events (request-scoped singleton).
 *
 *   correlation_id  shared by every event one business operation produces —
 *                   the HTTP request id when there is one, otherwise one id
 *                   per operation started with within();
 *   suppression     lets a richer semantic event stand in for a generic one
 *                   (a transfer records employee.transferred, so the generic
 *                   employee.assignment_changed for that employee is not
 *                   recorded twice).
 */
final class IntegrationEventContext
{
    private ?string $correlationId = null;

    /** @var array<string, true> "type|aggregate_uuid" */
    private array $suppressed = [];

    public function correlationId(): string
    {
        if ($this->correlationId !== null) {
            return $this->correlationId;
        }

        $requestId = app()->bound('correlation_id') ? app('correlation_id') : null;

        return $this->correlationId = is_string($requestId) && $requestId !== '' ? $requestId : (string) Str::uuid7();
    }

    /**
     * Runs one business operation under its own correlation id (console,
     * queue) and with the given generic events suppressed.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @param  list<array{0: string, 1: string}>  $suppress  [event_type, aggregate_uuid]
     * @return T
     */
    public function within(Closure $operation, array $suppress = [], ?string $correlationId = null): mixed
    {
        $previousCorrelation = $this->correlationId;
        $previousSuppressed = $this->suppressed;

        if ($correlationId !== null) {
            $this->correlationId = $correlationId;
        }
        foreach ($suppress as [$type, $aggregateUuid]) {
            $this->suppressed[$type.'|'.$aggregateUuid] = true;
        }

        try {
            return $operation();
        } finally {
            $this->correlationId = $previousCorrelation;
            $this->suppressed = $previousSuppressed;
        }
    }

    public function isSuppressed(string $type, string $aggregateUuid): bool
    {
        return isset($this->suppressed[$type.'|'.$aggregateUuid]);
    }
}
