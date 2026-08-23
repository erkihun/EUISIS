<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization-based cafeteria management.
 *
 * Adds service points (cafeterias) under each provider, and the assignment
 * table that decides which EUISIS organization each cafeteria may serve.
 *
 * Organizations are referenced by CODE plus a name snapshot — never by a
 * foreign key — because they live in the EUISIS database, which this
 * application has no access to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafeterias', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('cafeteria_providers')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('location')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->unsignedInteger('daily_capacity')->nullable();
            // e.g. ["mon","tue","wed","thu","fri"]
            $table->json('operating_days')->nullable();
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cafeteria_organization_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cafeteria_id')->constrained('cafeterias')->cascadeOnDelete();

            // EUISIS organization, referenced by business code + snapshot.
            $table->string('organization_code')->index();
            $table->string('organization_name_snapshot');
            $table->string('organization_type_snapshot')->nullable();
            $table->string('source_system_organization_id')->nullable();

            $table->string('status', 16)->default('active')->index();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            // Backs the "which cafeteria serves this organization today" lookup.
            // Explicit short name: the generated one exceeds MySQL's 64-char
            // identifier limit.
            $table->index(['organization_code', 'status', 'effective_from'], 'coa_org_status_effective_idx');
        });

        Schema::table('cafeteria_users', function (Blueprint $table): void {
            // Scanners and managers are bound to a single cafeteria; a
            // provider_admin leaves this null and manages the whole provider.
            $table->foreignUuid('cafeteria_id')->nullable()->after('provider_id')
                ->constrained('cafeterias')->nullOnDelete();
        });

        Schema::table('cafeteria_service_transactions', function (Blueprint $table): void {
            $table->foreignUuid('cafeteria_id')->nullable()->after('provider_id')
                ->constrained('cafeterias')->nullOnDelete();
            $table->string('organization_code')->nullable()->after('cafeteria_id')->index();
            // SHA-256 of the scanned token — never the token itself.
            $table->string('card_token_hash', 64)->nullable()->after('card_status');
            $table->date('service_date')->nullable()->after('served_at')->index();
            $table->string('service_type', 32)->default('meal')->after('service_date');
            $table->string('blocked_reason')->nullable()->after('service_type');
        });
    }

    public function down(): void
    {
        Schema::table('cafeteria_service_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cafeteria_id');
            $table->dropColumn(['organization_code', 'card_token_hash', 'service_date', 'service_type', 'blocked_reason']);
        });

        Schema::table('cafeteria_users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cafeteria_id');
        });

        Schema::dropIfExists('cafeteria_organization_assignments');
        Schema::dropIfExists('cafeterias');
    }
};
