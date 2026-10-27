<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Brings positions written without the job masters into line with them
 * (ADR 0010). Run by the P3 migration and again after core:import-from-euisis
 * copies EUISIS positions. Safe to repeat: nothing is dropped, and a value
 * already linked is left alone.
 *
 *  - Each distinct free-text grade becomes a job grade (code = the value) and
 *    its positions point at it.
 *  - An occupation referenced only by id gets a placeholder marked
 *    needs_review, so the reference holds until someone names it.
 *  - The lifecycle follows the older on/off switch where they disagree:
 *    on is approved, off is archived.
 */
final class PositionMasterBackfill
{
    public static function run(): void
    {
        $now = now();
        foreach (DB::table('positions')->whereNotNull('grade_level')->where('grade_level', '!=', '')->whereNull('job_grade_id')->distinct()->pluck('grade_level') as $grade) {
            $code = mb_substr(trim((string) $grade), 0, 64);
            $id = DB::table('job_grades')->where('code', $code)->value('id');
            if ($id === null) {
                $id = (string) Str::uuid7();
                DB::table('job_grades')->insert(['id' => $id, 'code' => $code, 'name_en' => trim((string) $grade), 'created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('positions')->where('grade_level', $grade)->whereNull('job_grade_id')->update(['job_grade_id' => $id]);
        }
        foreach (DB::table('positions')->whereNotNull('occupation_id')->distinct()->pluck('occupation_id') as $occupationId) {
            if (! DB::table('occupations')->where('id', $occupationId)->exists()) {
                DB::table('occupations')->insert(['id' => $occupationId, 'code' => 'IMPORTED-'.mb_substr((string) $occupationId, -12),
                    'name_en' => 'Imported occupation (to be named)', 'needs_review' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        DB::table('positions')->where('is_active', false)->where('lifecycle_status', 'approved')->update(['lifecycle_status' => 'archived']);
        DB::table('positions')->where('is_active', true)->where('lifecycle_status', '!=', 'approved')->update(['lifecycle_status' => 'approved']);
    }
}
