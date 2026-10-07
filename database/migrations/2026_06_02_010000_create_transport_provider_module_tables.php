<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureTransportCatalog();

        if (! Schema::hasTable('transport_provider_profiles')) {
            Schema::create('transport_provider_profiles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('provider_id')->unique()->constrained('providers')->cascadeOnDelete();
                $table->string('license_number')->nullable();
                $table->string('registration_number')->nullable();
                $table->string('contact_person')->nullable();
                $table->string('dispatch_phone')->nullable();
                $table->text('service_area_description_en')->nullable();
                $table->text('service_area_description_am')->nullable();
                $table->json('operating_days')->nullable();
                $table->json('operating_hours')->nullable();
                $table->string('status')->default('active');
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('transport_routes')) {
            Schema::create('transport_routes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
                $table->string('route_code');
                $table->string('name_en');
                $table->string('name_am')->nullable();
                $table->string('origin_en');
                $table->string('origin_am')->nullable();
                $table->string('destination_en');
                $table->string('destination_am')->nullable();
                $table->decimal('distance_km', 8, 2)->nullable();
                $table->unsignedInteger('estimated_duration_minutes')->nullable();
                $table->foreignUuid('assigned_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                $table->string('assigned_scope_type')->default('self');
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['provider_id', 'route_code']);
                $table->index(['provider_id', 'is_active']);
                $table->index('assigned_organization_id');
            });
        }

        if (! Schema::hasTable('transport_vehicles')) {
            Schema::create('transport_vehicles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
                $table->string('vehicle_code');
                $table->string('plate_number')->unique();
                $table->string('vehicle_type')->nullable();
                $table->unsignedInteger('capacity')->nullable();
                $table->string('status')->default('active');
                $table->foreignUuid('assigned_route_id')->nullable()->constrained('transport_routes')->nullOnDelete();
                $table->string('model')->nullable();
                $table->unsignedInteger('year')->nullable();
                $table->string('color')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['provider_id', 'vehicle_code']);
                $table->index(['provider_id', 'status']);
            });
        }

        if (! Schema::hasTable('transport_drivers')) {
            Schema::create('transport_drivers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
                $table->string('full_name');
                $table->string('phone_number')->nullable();
                $table->string('license_number')->nullable();
                $table->date('license_expiry_date')->nullable();
                $table->string('status')->default('active');
                $table->foreignUuid('assigned_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['provider_id', 'status']);
            });
        }

        if (! Schema::hasTable('transport_trips')) {
            Schema::create('transport_trips', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
                $table->foreignUuid('transport_route_id')->constrained('transport_routes')->cascadeOnDelete();
                $table->foreignUuid('transport_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
                $table->foreignUuid('transport_driver_id')->nullable()->constrained('transport_drivers')->nullOnDelete();
                $table->string('trip_number')->unique();
                $table->date('trip_date');
                $table->time('departure_time')->nullable();
                $table->time('arrival_time')->nullable();
                $table->string('status')->default('scheduled');
                $table->unsignedInteger('capacity')->nullable();
                $table->unsignedInteger('boarded_count')->default(0);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['provider_id', 'trip_date', 'status']);
            });
        }

        if (! Schema::hasTable('transport_passes')) {
            Schema::create('transport_passes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
                $table->foreignUuid('transport_route_id')->nullable()->constrained('transport_routes')->nullOnDelete();
                $table->date('valid_from');
                $table->date('valid_until')->nullable();
                $table->string('status')->default('active');
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('issued_at')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['provider_id', 'status']);
                $table->index(['employee_id', 'provider_id', 'status']);
            });
        }

        if (! Schema::hasTable('transport_transactions')) {
            Schema::create('transport_transactions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignUuid('id_card_id')->nullable()->constrained('id_cards')->nullOnDelete();
                $table->foreignUuid('transport_pass_id')->nullable()->constrained('transport_passes')->nullOnDelete();
                $table->foreignUuid('transport_route_id')->nullable()->constrained('transport_routes')->nullOnDelete();
                $table->foreignUuid('transport_trip_id')->nullable()->constrained('transport_trips')->nullOnDelete();
                $table->dateTime('scanned_at');
                $table->date('transaction_date');
                $table->string('status')->default('accepted');
                $table->string('result_code')->nullable();
                $table->string('rejection_reason')->nullable();
                $table->string('scan_nonce')->nullable()->unique();
                $table->string('qr_reference_hash')->nullable();
                $table->foreignUuid('scanned_by_provider_user_id')->nullable()->constrained('provider_users')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['provider_id', 'transaction_date', 'status']);
                $table->index(['employee_id', 'transaction_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_transactions');
        Schema::dropIfExists('transport_passes');
        Schema::dropIfExists('transport_trips');
        Schema::dropIfExists('transport_drivers');
        Schema::dropIfExists('transport_vehicles');
        Schema::dropIfExists('transport_routes');
        Schema::dropIfExists('transport_provider_profiles');
    }

    private function ensureTransportCatalog(): void
    {
        $now = now();

        DB::table('provider_types')->updateOrInsert(
            ['code' => 'TRANSPORT'],
            [
                'id' => DB::table('provider_types')->where('code', 'TRANSPORT')->value('id') ?? (string) Str::uuid7(),
                'name_en' => 'Transport',
                'name_am' => 'ትራንስፖርት',
                'is_active' => true,
                'sort_order' => 20,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('service_types')->updateOrInsert(
            ['code' => 'transport'],
            [
                'id' => DB::table('service_types')->where('code', 'transport')->value('id') ?? (string) Str::uuid7(),
                'name_en' => 'Transport',
                'name_am' => 'ትራንስፖርት',
                'module_key' => 'transport',
                'route_prefix' => '/provider/portal/transport',
                'icon' => 'bus',
                'is_active' => true,
                'sort_order' => 20,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }
};
