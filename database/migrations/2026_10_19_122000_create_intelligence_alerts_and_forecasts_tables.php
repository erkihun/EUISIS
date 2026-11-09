<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addis One Intelligence — alerts (brief §65–66) and forecast snapshots
 * (brief §69–70). An alert is one deduplicated condition from a metric, a
 * data-quality check, an incident or a forecast, acknowledged and resolved
 * by people; a forecast row is one predicted point of one snapshot, kept
 * forever next to the actual that later arrives and the error between them.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('intelligence_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 20);
            $table->string('domain', 10);
            $table->string('source_key', 120);
            $table->string('severity', 10);
            $table->string('status', 15)->default('open');
            $table->string('title', 190);
            $table->json('params')->nullable();
            $table->json('evidence')->nullable();
            $table->string('recommendation', 190)->nullable();
            $table->string('href', 500)->nullable();
            $table->uuid('organization_id')->nullable();
            $table->string('area', 40)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedSmallInteger('clear_runs')->default(0);
            $table->dateTime('first_detected_at');
            $table->dateTime('last_detected_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('cooldown_until')->nullable();
            $table->dateTime('last_notified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity'], 'intel_alerts_status_severity_idx');
            $table->index(['source_key', 'status'], 'intel_alerts_source_status_idx');
            $table->index(['domain', 'status', 'last_detected_at'], 'intel_alerts_domain_status_idx');
        });

        SchemaResume::create('intelligence_forecasts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('snapshot_id');
            $table->string('metric', 60);
            $table->string('scope_key', 64);
            $table->string('period', 7);
            $table->decimal('value', 14, 4);
            $table->decimal('lower', 14, 4)->nullable();
            $table->decimal('upper', 14, 4)->nullable();
            $table->string('method', 40);
            $table->string('model_version', 20);
            $table->string('confidence', 10)->nullable();
            $table->unsignedSmallInteger('points');
            $table->decimal('r2', 6, 4)->nullable();
            $table->json('assumptions')->nullable();
            $table->json('limitations')->nullable();
            $table->string('data_through', 7);
            $table->dateTime('generated_at');
            $table->decimal('actual', 14, 4)->nullable();
            $table->decimal('abs_error', 14, 4)->nullable();
            $table->decimal('pct_error', 8, 2)->nullable();
            $table->dateTime('evaluated_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['metric', 'scope_key', 'period'], 'intel_forecasts_metric_scope_period_idx');
            $table->index(['snapshot_id'], 'intel_forecasts_snapshot_idx');
            $table->index(['generated_at'], 'intel_forecasts_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_forecasts');
        Schema::dropIfExists('intelligence_alerts');
    }
};
