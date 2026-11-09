<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision (2026-11-07): a Grievance Team has a team leader and experts,
 * the Grievance Directorate a director and experts — there is no secretary.
 * Existing investigator/secretary terms become `expert`. A term now also
 * records why it ended (moved to another institution or unit, retired,
 * dismissed, other). Guarded so a retry on MySQL can resume.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('grievance_handler_unit_members')->whereIn('responsibility', ['investigator', 'secretary'])->update(['responsibility' => 'expert']);

        if (! Schema::hasColumn('grievance_handler_unit_members', 'end_reason_code')) {
            Schema::table('grievance_handler_unit_members', function (Blueprint $table): void {
                $table->string('end_reason_code', 40)->nullable()->after('end_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('grievance_handler_unit_members', 'end_reason_code')) {
            Schema::table('grievance_handler_unit_members', function (Blueprint $table): void {
                $table->dropColumn('end_reason_code');
            });
        }
        DB::table('grievance_handler_unit_members')->where('responsibility', 'expert')->update(['responsibility' => 'investigator']);
    }
};
