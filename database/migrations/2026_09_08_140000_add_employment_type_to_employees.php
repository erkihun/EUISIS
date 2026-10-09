<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an employee is engaged: permanent, contract, temporary and so on.
 *
 * Kept separate from `employees.status`, which is the record lifecycle
 * (active, suspended, retired). Nothing is read from or written to that column
 * here, so existing employees keep their current status untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'employment_type')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employment_type', 32)->nullable()->after('nationality');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'employment_type')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('employment_type');
        });
    }
};
