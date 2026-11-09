<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public service-feedback form (owner's sample, 2026-11-08): the kind of
 * feedback (constructive advice, an idea to improve how the institution
 * works, thanks) and the work unit or window where the service was received.
 * Both optional. Guarded for retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_service_feedback', 'feedback_kind')) {
                $table->string('feedback_kind', 20)->nullable()->after('rating');
            }
            if (! Schema::hasColumn('employee_service_feedback', 'service_point')) {
                $table->string('service_point', 120)->nullable()->after('feedback_kind');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            foreach (['service_point', 'feedback_kind'] as $column) {
                if (Schema::hasColumn('employee_service_feedback', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
