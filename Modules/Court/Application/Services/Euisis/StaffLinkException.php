<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use RuntimeException;

/** Why a staff account cannot be linked to an EUISIS employee; `reason` is a key of lang/{en,am}/euisis_staff.php `errors`. */
final class StaffLinkException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
