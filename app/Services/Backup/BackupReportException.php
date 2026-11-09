<?php

namespace App\Services\Backup;

use RuntimeException;

/** Why the ops-published report could not be read: a reason code, never file contents. */
final class BackupReportException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
