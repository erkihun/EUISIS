<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request log for the integration API.
 *
 * Deliberately records only routing and outcome metadata — never request or
 * response bodies — so employee data can never leak into the log table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('external_application_id')->nullable()
                ->constrained('external_applications')->nullOnDelete();
            $table->string('endpoint');
            $table->string('method', 10);
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->boolean('success')->default(false);
            $table->string('failure_reason')->nullable();
            $table->timestamp('requested_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['external_application_id', 'requested_at']);
        });

        // Fields the API Management UI needs beyond the initial registry.
        Schema::table('external_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('external_applications', 'owner_institution')) {
                $table->string('owner_institution')->nullable()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');

        Schema::table('external_applications', function (Blueprint $table): void {
            if (Schema::hasColumn('external_applications', 'owner_institution')) {
                $table->dropColumn('owner_institution');
            }
        });
    }
};
