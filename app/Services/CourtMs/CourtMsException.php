<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use RuntimeException;

/**
 * A court-ms call that did not succeed. `retryable` is false when court-ms
 * refused the content (a 4xx other than 408/429): sending it again unchanged
 * would fail again, so the referral is marked failed for a person to fix.
 */
final class CourtMsException extends RuntimeException
{
    /** @param  array<string, list<string>>  $errors */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $retryable = true,
        public readonly array $errors = [],
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
