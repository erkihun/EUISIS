<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master backbone remediation (docs/architecture/master-backbone-remediation-implementation.md).
 *
 * F2 — partner organization bounds. An external application now carries an
 *      organization access mode:
 *        city       — explicitly approved for every organization
 *        scoped     — only the organizations in
 *                     external_application_organization_scopes (self or subtree)
 *        unreviewed — legacy application whose bounds nobody has decided yet:
 *                     organization-bound data is DENIED until an administrator
 *                     chooses city or scoped. Existing rows get this value, so
 *                     no application is silently left with city-wide reads.
 *
 * F16 — machine-principal provider binding: service_provider_id ties an
 *      application to the one service provider it acts for on the provider
 *      service routes.
 *
 * F6 — durable, atomic idempotency: api_idempotency_keys holds one claim per
 *      (principal, route, key) under a unique index, with the request
 *      fingerprint and the stored response.
 *
 * Additive only. Nothing is dropped or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = Schema::hasTable('external_applications') && ! Schema::hasColumn('external_applications', 'organization_access');

        Schema::table('external_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('external_applications', 'organization_access')) {
                $table->string('organization_access', 20)->default('scoped')->after('status');
            }
            if (! Schema::hasColumn('external_applications', 'service_provider_id')) {
                $table->uuid('service_provider_id')->nullable()->after('organization_access');
                $table->index('service_provider_id', 'external_applications_provider_idx');
            }
        });

        // Security-first default for applications that existed before bounds:
        // deny organization-bound data until reviewed, never over-grant.
        if ($existing) {
            DB::table('external_applications')->update(['organization_access' => 'unreviewed']);
        }

        SchemaResume::create('external_application_organization_scopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // external_applications.id is VARCHAR(36) (aligned with the Sanctum
            // tokenable_id column in 2026_08_14_000300), so the key matches it.
            $table->string('external_application_id', 36);
            // Named: the generated name is 72 characters, over MySQL's 64-character limit.
            $table->foreign('external_application_id', 'eaos_application_fk')->references('id')->on('external_applications')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('scope_type', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['external_application_id', 'organization_id'], 'external_app_org_scopes_unique');
            $table->index(['organization_id', 'scope_type'], 'external_app_org_scopes_org_idx');
        });

        SchemaResume::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('principal_type', 40);
            $table->string('principal_id', 64);
            $table->string('route', 191);
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->string('status', 20);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->dateTime('created_at'); // dateTime: a NOT NULL TIMESTAMP needs a default on MySQL
            $table->timestamp('completed_at')->nullable();
            $table->dateTime('expires_at'); // dateTime: a NOT NULL TIMESTAMP needs a default on MySQL

            $table->unique(['principal_type', 'principal_id', 'route', 'idempotency_key'], 'api_idempotency_keys_unique');
            $table->index('expires_at', 'api_idempotency_keys_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('external_application_organization_scopes');
        Schema::table('external_applications', function (Blueprint $table): void {
            if (Schema::hasColumn('external_applications', 'service_provider_id')) {
                $table->dropIndex('external_applications_provider_idx');
                $table->dropColumn('service_provider_id');
            }
            if (Schema::hasColumn('external_applications', 'organization_access')) {
                $table->dropColumn('organization_access');
            }
        });
    }
};
