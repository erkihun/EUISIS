<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public history must not disappear with its target (QA brief §65). The
 * original table cascaded deletes from `employees` into
 * `employee_service_feedback`; a hard delete of an employee now stops at the
 * foreign key instead, exactly as it already does for institution targets.
 * Employees are retired by status, never deleted, so nothing changes for
 * normal operation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot alter a constraint in place; the tests enforce the
            // rule through the PostgreSQL constraint and the model.
            return;
        }

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->dropForeign(['employee_id']);
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->dropForeign(['employee_id']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }
};
