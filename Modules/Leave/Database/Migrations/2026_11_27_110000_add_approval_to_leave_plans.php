<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The leave schedule is approved by the institution (owner decision,
 * 2026-11-27): each planned period waits for the supervisor's decision.
 * Periods planned before this change start as waiting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leave_plans', 'status')) {
            return;
        }
        Schema::table('leave_plans', function (Blueprint $table): void {
            // pending: waiting for a decision; approved; rejected: sent back with a reason.
            $table->string('status', 20)->default('pending')->after('note');
            $table->unsignedBigInteger('decided_by')->nullable()->after('status');
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('decision_comment', 1000)->nullable()->after('decided_at');
            $table->index(['organization_id', 'budget_year', 'status'], 'leave_plans_org_status_ix');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('leave_plans', 'status')) {
            return;
        }
        Schema::table('leave_plans', function (Blueprint $table): void {
            $table->dropIndex('leave_plans_org_status_ix');
            $table->dropColumn(['status', 'decided_by', 'decided_at', 'decision_comment']);
        });
    }
};
