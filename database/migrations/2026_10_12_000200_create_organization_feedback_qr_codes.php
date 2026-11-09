<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The organization public feedback QR identity (brief §9) and the permissions
 * that govern it. The permissions are created but granted to no role: an
 * administrator decides who may mint, disable or rotate institutional codes.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'service_feedback.organization_qr.view',
        'service_feedback.organization_qr.create',
        'service_feedback.organization_qr.export',
        'service_feedback.organization_qr.disable',
        'service_feedback.organization_qr.rotate',
    ];

    public function up(): void
    {
        Schema::create('organization_feedback_qr_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            // Opaque 64-hex token; unique across every row so a revoked value is never reissued.
            $table->string('public_token', 64)->unique();
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->uuid('rotated_from_id')->nullable()->index();
            $table->timestamp('last_scanned_at')->nullable();
            $table->unsignedInteger('scan_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        // One active code per organization (brief §10), enforced by the database.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // No partial indexes on MySQL: a stored column carries the organization of active rows only, and the unique index sits on it.
            DB::statement("ALTER TABLE organization_feedback_qr_codes ADD COLUMN active_organization_id CHAR(36) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN organization_id ELSE NULL END) STORED");
            DB::statement('CREATE UNIQUE INDEX org_feedback_qr_one_active ON organization_feedback_qr_codes (active_organization_id)');
        } else {
            DB::statement(
                "CREATE UNIQUE INDEX org_feedback_qr_one_active ON organization_feedback_qr_codes (organization_id) WHERE status = 'active'"
            );
        }

        if (DB::pretending()) {
            return;
        }

        $catalog = require database_path('seeders/data/permissions.php');

        foreach ($catalog as $entry) {
            if (in_array($entry['name'], self::PERMISSIONS, true)) {
                Permission::firstOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], $entry);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('employee_service_feedback')->whereNotNull('source_organization_qr_id')->exists()) {
            throw new RuntimeException('Submissions reference organization feedback QR codes; export them before dropping the table.');
        }

        Schema::dropIfExists('organization_feedback_qr_codes');

        // Permissions and their grants are preserved for audit history.
    }
};
