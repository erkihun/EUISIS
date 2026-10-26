<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P4/P5 performance review (ADR 0011), measured on 300,000 synthetic
 * employees: the possible-duplicate check (same date of birth and lower-cased
 * name, also what registration looks up) took 80 seconds without an index on
 * that pair. Only this index was justified by the plans; the other lists and
 * checks already use existing indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement('CREATE INDEX employees_dob_lower_name_idx ON employees (date_of_birth, lower(full_name))'),
            // MariaDB has no expression index; its default collation compares names without case anyway,
            // and the date of birth leads, so the lookup still narrows to one day's births (ADR 0012).
            'mysql', 'mariadb' => DB::statement('CREATE INDEX employees_dob_lower_name_idx ON employees (date_of_birth, full_name)'),
            default => null,
        };
    }

    public function down(): void
    {
        DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb'
            ? DB::statement('DROP INDEX employees_dob_lower_name_idx ON employees')
            : DB::statement('DROP INDEX IF EXISTS employees_dob_lower_name_idx');
    }
};
