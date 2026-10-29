<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce statistics an employee record carries beyond age and gender:
 * marital status, education level and field of study, a declared disability,
 * and the date the person first entered public service (years of service,
 * retirement planning). All optional; a null means "not recorded".
 */
return new class extends Migration
{
    private const COLUMNS = [
        'marital_status', 'education_level_id', 'field_of_study',
        'has_disability', 'disability_type', 'service_start_date',
    ];

    public function up(): void
    {
        $add = fn (string $column): bool => ! Schema::hasColumn('employees', $column);

        Schema::table('employees', function (Blueprint $table) use ($add): void {
            if ($add('marital_status')) {
                $table->string('marital_status', 20)->nullable();
            }
            if ($add('education_level_id')) {
                $table->foreignUuid('education_level_id')->nullable()->constrained('education_levels')->nullOnDelete();
            }
            if ($add('field_of_study')) {
                $table->string('field_of_study', 150)->nullable();
            }
            if ($add('has_disability')) {
                $table->boolean('has_disability')->nullable();
            }
            if ($add('disability_type')) {
                $table->string('disability_type', 20)->nullable();
            }
            if ($add('service_start_date')) {
                $table->date('service_start_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'education_level_id')) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropConstrainedForeignId('education_level_id'));
        }

        $present = array_values(array_filter(self::COLUMNS, fn (string $column): bool => $column !== 'education_level_id' && Schema::hasColumn('employees', $column)));

        if ($present !== []) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
