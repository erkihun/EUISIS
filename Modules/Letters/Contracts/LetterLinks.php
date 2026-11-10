<?php

declare(strict_types=1);

namespace Modules\Letters\Contracts;

use Modules\Letters\Contracts\Data\LetterSummary;

/** The letters issued for another module's record, for that module's own page. */
interface LetterLinks
{
    /** @return list<LetterSummary> newest first */
    public function forRecord(string $regardingType, string $regardingId): array;
}
