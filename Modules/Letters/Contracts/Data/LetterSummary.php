<?php

declare(strict_types=1);

namespace Modules\Letters\Contracts\Data;

/** What callers learn about a letter: enough to link to it, never the body. */
final class LetterSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $direction,
        public readonly string $status,
        public readonly string $subject,
        /** The sending institution's registry number, once drawn. */
        public readonly ?string $referenceNumber,
        public readonly string $letterDate,
        /** The letter's page in the Letters module. */
        public readonly string $url,
    ) {}
}
