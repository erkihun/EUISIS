<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cafeteria service networks (docs/cafeteria-network-access.md).
 *
 * `cafeteria_providers` stays the cafeteria LOCATION table: every transaction,
 * menu, order, terminal and special day already points at it. A provider (the
 * payee, `providers`) now owns networks, and each network holds a main
 * cafeteria, branches and service points. Access, assignment and policy are
 * explicit per organization — nothing is inherited from the physical location.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafeteria_service_networks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('providers')->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider_id', 'status']);
        });

        // A provider may now run several cafeterias: keep an index for the
        // foreign key before dropping the one-cafeteria-per-provider unique.
        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            $table->index('provider_id', 'cafeteria_providers_provider_idx');
        });
        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            $table->dropUnique('cafeteria_providers_provider_id_unique');
        });

        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            $table->foreignUuid('cafeteria_service_network_id')->nullable()->after('provider_id')
                ->constrained('cafeteria_service_networks')->nullOnDelete();
            $table->uuid('parent_cafeteria_id')->nullable()->after('cafeteria_service_network_id');
            $table->string('location_type', 20)->default('main')->after('parent_cafeteria_id');
            $table->string('operational_status', 30)->default('open')->after('location_type');
            $table->time('opening_time')->nullable()->after('operational_status');
            $table->time('closing_time')->nullable()->after('opening_time');
            $table->unsignedInteger('capacity')->nullable()->after('closing_time');

            $table->foreign('parent_cafeteria_id', 'cafeteria_providers_parent_fk')
                ->references('id')->on('cafeteria_providers')->nullOnDelete();
            $table->index(['cafeteria_service_network_id', 'location_type'], 'cafeteria_providers_network_type_idx');
        });

        Schema::create('organization_cafeteria_access', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            // Explicit name: the generated one exceeds MySQL's 64-character limit.
            $table->uuid('cafeteria_service_network_id');
            $table->foreign('cafeteria_service_network_id', 'org_caf_access_network_fk')
                ->references('id')->on('cafeteria_service_networks')->restrictOnDelete();
            $table->uuid('primary_cafeteria_id')->nullable();
            $table->boolean('allow_cross_location_usage')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('pending_approval')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('primary_cafeteria_id', 'org_caf_access_primary_fk')
                ->references('id')->on('cafeteria_providers')->nullOnDelete();
            $table->index(['organization_id', 'cafeteria_service_network_id', 'status'], 'org_caf_access_lookup_idx');
            $table->index(['effective_from', 'effective_to'], 'org_caf_access_dates_idx');
        });

        Schema::create('organization_cafeteria_location_access', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_cafeteria_access_id');
            $table->uuid('cafeteria_id');
            $table->boolean('is_allowed');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('organization_cafeteria_access_id', 'org_caf_loc_access_fk')
                ->references('id')->on('organization_cafeteria_access')->cascadeOnDelete();
            $table->foreign('cafeteria_id', 'org_caf_loc_cafeteria_fk')
                ->references('id')->on('cafeteria_providers')->cascadeOnDelete();
            $table->index(['organization_cafeteria_access_id', 'cafeteria_id'], 'org_caf_loc_lookup_idx');
        });

        Schema::create('cafeteria_service_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('provider_id')->constrained('providers')->restrictOnDelete();
            $table->uuid('cafeteria_service_network_id')->nullable();
            $table->uuid('cafeteria_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('pending_approval')->index();
            $table->text('notes')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('cafeteria_service_network_id', 'caf_assign_network_fk')
                ->references('id')->on('cafeteria_service_networks')->restrictOnDelete();
            $table->foreign('cafeteria_id', 'caf_assign_cafeteria_fk')
                ->references('id')->on('cafeteria_providers')->restrictOnDelete();
            $table->index(['organization_id', 'provider_id', 'status'], 'caf_assign_lookup_idx');
            $table->index(['effective_from', 'effective_to'], 'caf_assign_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_service_assignments');
        Schema::dropIfExists('organization_cafeteria_location_access');
        Schema::dropIfExists('organization_cafeteria_access');

        // Foreign keys first: on MySQL the composite index backs the network
        // foreign key and cannot be dropped while that key exists.
        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            $table->dropForeign('cafeteria_providers_parent_fk');
            $table->dropForeign(['cafeteria_service_network_id']);
        });
        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            $table->dropIndex('cafeteria_providers_network_type_idx');
            $table->dropColumn(['cafeteria_service_network_id', 'parent_cafeteria_id', 'location_type', 'operational_status', 'opening_time', 'closing_time', 'capacity']);
        });

        // Restoring the one-cafeteria-per-provider unique fails if a provider
        // now runs several cafeterias; that data must be split first.
        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            $table->unique('provider_id', 'cafeteria_providers_provider_id_unique');
        });
        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            $table->dropIndex('cafeteria_providers_provider_idx');
        });

        Schema::dropIfExists('cafeteria_service_networks');
    }
};
