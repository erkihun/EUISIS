<?php

declare(strict_types=1);

namespace App\Services\Backup;

use JsonException;

class BackupReportReader
{
    /** @throws BackupReportException */
    public function read(): array
    {
        // Local files only: no URL wrappers, HTTP parameters, subprocesses or credential access.
        $path = (string) config('backup.report_path');
        match (true) {
            $path === '' => throw new BackupReportException('REPORT_NOT_CONFIGURED'),
            str_contains($path, '://') => throw new BackupReportException('INVALID_CONFIGURATION'),
            ! is_file($path) => throw new BackupReportException('REPORT_NOT_FOUND'),
            ! is_readable($path) => throw new BackupReportException('PERMISSION_DENIED'),
            default => null,
        };
        $contents = file_get_contents($path, false, null, 0, 1048577);
        if ($contents === false || strlen($contents) > 1048576) {
            throw new BackupReportException('INVALID_REPORT');
        }
        try {
            $report = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupReportException('INVALID_REPORT');
        }
        if (! is_array($report)) {
            throw new BackupReportException('INVALID_REPORT');
        }

        return $report;
    }
}
