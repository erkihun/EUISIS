<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Support;

use RuntimeException;

/** Optimistic lock failure: someone else saved the record after the caller read it. The presentation layer answers 409. */
final class StaleVersion extends RuntimeException
{
    public function __construct(public readonly int $currentLockVersion, public readonly ?string $savedByName = null)
    {
        parent::__construct('reporting_stale_version');
    }
}
