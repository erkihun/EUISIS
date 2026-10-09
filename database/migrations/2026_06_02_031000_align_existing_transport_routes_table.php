<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_routes')) {
            return;
        }

        Schema::table('transport_routes', function (Blueprint $table): void {
            if (! Schema::hasColumn('transport_routes', 'provider_id')) {
                $table->foreignUuid('provider_id')->nullable()->after('id')->constrained('providers')->nullOnDelete();
            }

            if (! Schema::hasColumn('transport_routes', 'route_code')) {
                $table->string('route_code')->nullable()->after('provider_id');
            }

            if (! Schema::hasColumn('transport_routes', 'name_en')) {
                $table->string('name_en')->nullable()->after('route_code');
            }

            if (! Schema::hasColumn('transport_routes', 'name_am')) {
                $table->string('name_am')->nullable()->after('name_en');
            }

            if (! Schema::hasColumn('transport_routes', 'origin_en')) {
                $table->string('origin_en')->nullable()->after('name_am');
            }

            if (! Schema::hasColumn('transport_routes', 'origin_am')) {
                $table->string('origin_am')->nullable()->after('origin_en');
            }

            if (! Schema::hasColumn('transport_routes', 'destination_en')) {
                $table->string('destination_en')->nullable()->after('origin_am');
            }

            if (! Schema::hasColumn('transport_routes', 'destination_am')) {
                $table->string('destination_am')->nullable()->after('destination_en');
            }

            if (! Schema::hasColumn('transport_routes', 'distance_km')) {
                $table->decimal('distance_km', 8, 2)->nullable()->after('destination_am');
            }

            if (! Schema::hasColumn('transport_routes', 'estimated_duration_minutes')) {
                $table->unsignedInteger('estimated_duration_minutes')->nullable()->after('distance_km');
            }

            if (! Schema::hasColumn('transport_routes', 'assigned_organization_id')) {
                $table->foreignUuid('assigned_organization_id')->nullable()->after('estimated_duration_minutes')->constrained('organizations')->nullOnDelete();
            }

            if (! Schema::hasColumn('transport_routes', 'assigned_scope_type')) {
                $table->string('assigned_scope_type')->default('self')->after('assigned_organization_id');
            }

            if (! Schema::hasColumn('transport_routes', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('assigned_scope_type');
            }

            if (! Schema::hasColumn('transport_routes', 'notes')) {
                $table->text('notes')->nullable()->after('is_active');
            }

            if (! Schema::hasColumn('transport_routes', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('transport_routes', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transport_routes')) {
            return;
        }

        Schema::table('transport_routes', function (Blueprint $table): void {
            foreach ([
                'provider_id',
                'route_code',
                'name_en',
                'name_am',
                'origin_en',
                'origin_am',
                'destination_en',
                'destination_am',
                'distance_km',
                'estimated_duration_minutes',
                'assigned_organization_id',
                'assigned_scope_type',
                'is_active',
                'notes',
                'created_by',
                'updated_by',
            ] as $column) {
                if (Schema::hasColumn('transport_routes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
