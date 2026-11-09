<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Contracts\Data;

final readonly class CafeteriaScanOutcome
{
    public function __construct(
        /** Served: the rules allowed it and it is not a repeat of an earlier scan. */
        public bool $allowed,
        /** A retry of a scan already recorded (same terminal and reference). */
        public bool $duplicate,
        public ?string $resultCode,
        /** The specific cause when refused (the result code is then 'rejected'). */
        public ?string $denialReason,
        /** Set when the scan was recorded. */
        public ?string $transactionNumber,
    ) {}
}
