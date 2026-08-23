<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which endpoints each external application is permitted to call.
 *
 * Scopes alone are too coarse: two endpoints can share one scope, so granting
 * `reports.read_limited` would otherwise expose settlements to an integration
 * that only needs the organization directory. This pivot narrows access to the
 * specific endpoints an application was approved for.
 *
 * Column types follow external_applications: `id` is VARCHAR(36) and
 * `created_by` is VARCHAR(64), because users.id is a BIGINT and declaring it
 * UUID broke inserts on that table already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_application_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('external_application_id', 36);
            $table->uuid('api_endpoint_definition_id');
            $table->string('allowed_scope', 64)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('created_by', 64)->nullable();
            $table->timestamps();

            // One row per application/endpoint pair.
            $table->unique(
                ['external_application_id', 'api_endpoint_definition_id'],
                'ext_app_endpoint_unique',
            );
            $table->index(['external_application_id', 'is_enabled'], 'ext_app_endpoint_enabled_idx');

            $table->foreign('external_application_id', 'ext_app_endpoint_app_fk')
                ->references('id')->on('external_applications')
                ->cascadeOnDelete();
            $table->foreign('api_endpoint_definition_id', 'ext_app_endpoint_def_fk')
                ->references('id')->on('api_endpoint_definitions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_application_endpoints');
    }
};
