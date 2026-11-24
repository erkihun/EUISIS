<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Support;

use RuntimeException;

/** The actor lacks the permission or the organization scope for the operation. The presentation layer answers 403. */
final class ReportingAccessDenied extends RuntimeException
{
    public function __construct(public readonly string $permission = '')
    {
        parent::__construct('reporting_access_denied');
    }
}
