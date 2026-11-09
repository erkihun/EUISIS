<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional employee information: residential address, nationality and the
 * next-of-kin contact reached in an emergency.
 *
 * Employment status is deliberately NOT added here — `employees.status` already
 * holds it as an EmployeeStatus enum, and the CSV importer already maps its
 * `employment_status` column onto that same field. A second column would give
 * the record two competing answers to one question.
 *
 * Every column is nullable so existing rows stay valid without a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'address')) {
                $table->text('address')->nullable()->after('email');
            }

            if (! Schema::hasColumn('employees', 'nationality')) {
                $table->string('nationality', 100)->nullable()->after('address');
            }

            if (! Schema::hasColumn('employees', 'emergency_contact_name')) {
                $table->string('emergency_contact_name')->nullable()->after('nationality');
            }

            if (! Schema::hasColumn('employees', 'emergency_contact_phone')) {
                $table->string('emergency_contact_phone', 50)->nullable()->after('emergency_contact_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn([
                'address',
                'nationality',
                'emergency_contact_name',
                'emergency_contact_phone',
            ]);
        });
    }
};
