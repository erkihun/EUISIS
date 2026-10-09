<?php

declare(strict_types=1);

namespace App\Services\OrganizationalChange;

use App\Models\OrganizationalChangeRequest;
use Illuminate\Support\Facades\DB;

/**
 * Centralised request numbering: OCR-{year}-{6-digit sequence}.
 *
 * The sequence is per calendar year and is derived under a row lock on the
 * existing rows for that year, so two concurrent submissions cannot take the
 * same number. The unique index on request_no is the final guard.
 */
final class ChangeRequestNumberGenerator
{
    private const PREFIX = 'OCR';

    public function generate(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = self::PREFIX.'-'.$year.'-';

        return DB::transaction(function () use ($prefix): string {
            $last = OrganizationalChangeRequest::query()
                ->withTrashed()
                ->where('request_no', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('request_no')
                ->value('request_no');

            $next = $last === null
                ? 1
                : ((int) substr((string) $last, strlen($prefix))) + 1;

            return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }
}
