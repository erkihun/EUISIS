<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Facades\URL;

/**
 * The holder's photo on a scan result (cafeteria scanner, provider portal).
 *
 * Operators must compare the face with the person at the counter, but they
 * hold no employee-registry permission and the provider portal signs in on
 * its own guard, so the authorized staff photo route cannot serve them. The
 * scan result therefore carries a signed link that expires shortly after the
 * scan; ScanPhotoController checks only the signature.
 */
final class EmployeeScanPhoto
{
    public const TTL_MINUTES = 10;

    public static function url(?Employee $employee): ?string
    {
        if ($employee === null || blank($employee->photo_path)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'employees.scan-photo',
            now()->addMinutes(self::TTL_MINUTES),
            ['employee' => $employee->getKey()],
            absolute: false,
        );
    }
}
