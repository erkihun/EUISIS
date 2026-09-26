<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Effective-dated, versioned cafeteria service policy (docs/cafeteria-policy-architecture.md).
 *
 * One scope = organization + provider + (network | cafeteria | provider-wide);
 * `scope_key` names it so overlap checks and row locks target one scope. A
 * financial change is a new version (same `policy_group_id`, next
 * `version_no`, `supersedes_policy_id` → the previous one); approved versions
 * are never edited, so a transaction's snapshot always matches its policy row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafeteria_service_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('policy_group_id')->index();
            $table->unsignedInteger('version_no')->default(1);
            $table->uuid('cafeteria_service_assignment_id');
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('provider_id')->constrained('providers')->restrictOnDelete();
            $table->uuid('cafeteria_service_network_id')->nullable();
            $table->uuid('cafeteria_id')->nullable();
            $table->string('scope_key', 200)->index();

            $table->decimal('daily_subsidy_amount', 12, 2);
            $table->decimal('employee_contribution_amount', 12, 2)->default(0);
            $table->decimal('provider_price', 12, 2);
            $table->string('currency_code', 3)->default('ETB');

            $table->unsignedTinyInteger('max_daily_uses')->default(1);
            $table->boolean('allow_advance_usage')->default(false);
            $table->unsignedTinyInteger('advance_max_days')->nullable();
            $table->string('extra_scan_policy', 40)->default('block');

            $table->boolean('monday_enabled')->default(true);
            $table->boolean('tuesday_enabled')->default(true);
            $table->boolean('wednesday_enabled')->default(true);
            $table->boolean('thursday_enabled')->default(true);
            $table->boolean('friday_enabled')->default(true);
            $table->boolean('saturday_enabled')->default(false);
            $table->boolean('sunday_enabled')->default(false);

            $table->boolean('exclude_public_holidays')->default(true);
            $table->boolean('block_employee_leave')->default(true);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->uuid('supersedes_policy_id')->nullable();
            // The predecessor's end date before approval trimmed it — restored
            // if this version is cancelled before it takes effect.
            $table->boolean('predecessor_trimmed')->default(false);
            $table->date('predecessor_effective_to')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign('cafeteria_service_assignment_id', 'caf_policy_assignment_fk')
                ->references('id')->on('cafeteria_service_assignments')->restrictOnDelete();
            $table->foreign('cafeteria_service_network_id', 'caf_policy_network_fk')
                ->references('id')->on('cafeteria_service_networks')->restrictOnDelete();
            $table->foreign('cafeteria_id', 'caf_policy_cafeteria_fk')
                ->references('id')->on('cafeteria_providers')->restrictOnDelete();
            $table->foreign('supersedes_policy_id', 'caf_policy_supersedes_fk')
                ->references('id')->on('cafeteria_service_policies')->nullOnDelete();

            $table->unique(['policy_group_id', 'version_no'], 'caf_policy_group_version_unique');
            $table->index(['organization_id', 'provider_id', 'status'], 'caf_policy_lookup_idx');
            $table->index(['scope_key', 'status', 'effective_from'], 'caf_policy_scope_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_service_policies');
    }
};
