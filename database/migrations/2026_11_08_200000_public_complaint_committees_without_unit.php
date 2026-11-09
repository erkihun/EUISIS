<?php

declare(strict_types=1);

// migration-safety: reviewed — a NOT NULL column becomes nullable and two nullable columns are added, on a table created today.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0035 (owner follow-up 2026-11-08): the complaint committee, the
 * appeal committee and the head's office are institution bodies, not work
 * units — their members come from any unit of the institution. Such a body
 * has no organization_unit_id and carries its own name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_case_handling_units', function (Blueprint $table): void {
            $table->uuid('organization_unit_id')->nullable()->change();
        });
        if (! Schema::hasColumn('public_case_handling_units', 'name_en')) {
            Schema::table('public_case_handling_units', function (Blueprint $table): void {
                $table->string('name_en', 200)->nullable()->after('stage');
                $table->string('name_am', 200)->nullable()->after('name_en');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('public_case_handling_units', 'name_en')) {
            Schema::table('public_case_handling_units', function (Blueprint $table): void {
                $table->dropColumn(['name_en', 'name_am']);
            });
        }
    }
};
