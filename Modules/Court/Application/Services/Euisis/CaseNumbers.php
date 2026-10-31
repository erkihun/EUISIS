<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Modules\Court\Domain\Models\CaseType;
use Illuminate\Support\Facades\DB;

/**
 * Case number and public code for a referred case, by the same rules as a
 * case an applicant files (ApplicantCaseController / Api\CaseController keep
 * their own copies; this one is separate so the live controllers are not
 * touched). Call inside the transaction that inserts the case.
 */
final class CaseNumbers
{
    public function next(int $caseTypeId): string
    {
        $caseType = CaseType::query()->findOrFail($caseTypeId);
        $prefixBase = (string) ($caseType->prifix ?? $caseType->prefix ?? $caseType->name ?? 'CASE');
        $clean = preg_replace('/[\s\p{P}]+/u', '', $prefixBase);
        $prefix = mb_strtoupper(mb_substr($clean ?: 'CASE', 0, 4)) ?: 'CASE';
        $year = now()->format('y');

        $maxSeq = DB::table('court_cases')
            ->where('case_number', 'LIKE', "{$prefix}/%/{$year}")
            ->lockForUpdate()
            ->selectRaw("MAX(".\Modules\Court\Application\Support\Sql::middleSegmentNumber('case_number').") as max_seq")
            ->value('max_seq');

        return $prefix.'/'.str_pad((string) (((int) $maxSeq) + 1), 5, '0', STR_PAD_LEFT).'/'.$year;
    }

    public function code(): string
    {
        do {
            $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (DB::table('court_cases')->where('code', $code)->exists());

        return $code;
    }
}
