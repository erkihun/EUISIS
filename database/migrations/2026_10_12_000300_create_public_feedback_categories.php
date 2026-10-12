<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Public feedback categories (brief §31–33): city-wide when organization_id
 * is null, otherwise an institution's own extension; applicable by submission
 * type and by target type; retired, never deleted, once referenced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_feedback_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->json('submission_types');
            $table->json('target_types');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        // A unique pair with a NULL organization does not constrain city-wide codes; this does.
        DB::statement('CREATE UNIQUE INDEX pfc_city_wide_code_unique ON public_feedback_categories (code) WHERE organization_id IS NULL');

        if (DB::getDriverName() !== 'sqlite') {
            // On SQLite adding a foreign key rebuilds the table; the model and
            // the request validate the reference on every driver.
            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                $table->foreign('category_id')->references('id')->on('public_feedback_categories')->restrictOnDelete();
            });
        }

        if (DB::pretending()) {
            return;
        }

        $catalog = require database_path('seeders/data/permissions.php');

        foreach ($catalog as $entry) {
            if ($entry['name'] === 'service_feedback.manage_categories') {
                Permission::firstOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], $entry);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('employee_service_feedback')->whereNotNull('category_id')->exists()) {
            throw new RuntimeException('Submissions reference public feedback categories; clear category_id before dropping the table.');
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                $table->dropForeign(['category_id']);
            });
        }

        Schema::dropIfExists('public_feedback_categories');
    }
};
