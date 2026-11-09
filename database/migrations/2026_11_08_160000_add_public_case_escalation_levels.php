<?php

declare(strict_types=1);

// migration-safety: reviewed — additive columns with defaults; existing rules become level 1 with no deadline.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalation ladder and deadlines for public Tip-offs and complaints (ADR 0033).
 * A routing rule belongs to one level of the ladder and may give the handler
 * a number of days to act; an assignment remembers its level and its due date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_feedback_routing_rules', function (Blueprint $table): void {
            $table->unsignedTinyInteger('escalation_level')->default(1)->after('priority');
            $table->unsignedSmallInteger('response_days')->nullable()->after('escalation_level');
        });

        Schema::table('public_feedback_assignments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('level')->default(1)->after('routing_rule_id');
            $table->timestamp('due_at')->nullable()->after('level');
            $table->boolean('escalated')->default(false)->after('ended_by');
            $table->index(['ended_at', 'due_at'], 'pfa_open_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('public_feedback_assignments', function (Blueprint $table): void {
            $table->dropIndex('pfa_open_due_idx');
            $table->dropColumn(['level', 'due_at', 'escalated']);
        });

        Schema::table('public_feedback_routing_rules', function (Blueprint $table): void {
            $table->dropColumn(['escalation_level', 'response_days']);
        });
    }
};
