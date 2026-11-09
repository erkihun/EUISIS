<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Granular visibility permissions (brief §36, 57, 86, 95) and an encrypted
 * submitter contact (§35).
 *
 * Seeing who sent a submission and its evidence become separate grants that
 * no role receives here. Seeing employee-targeted and organization-targeted
 * submissions become separate grants too; so that nobody loses what they can
 * see today, both are given to every role that already views submissions.
 *
 * `client_contact` widens to text because an encrypted value is longer than
 * the plaintext it protects; rows written before this read through unchanged.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'service_feedback.view_submitter_identity',
        'service_feedback.view_sensitive_attachments',
        'service_feedback.view_employee_target',
        'service_feedback.view_organization_target',
    ];

    public function up(): void
    {
        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->text('client_contact')->nullable()->change();
        });

        if (DB::pretending()) {
            return;
        }

        $catalog = require database_path('seeders/data/permissions.php');

        foreach ($catalog as $entry) {
            if (in_array($entry['name'], self::PERMISSIONS, true)) {
                Permission::firstOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], $entry);
            }
        }

        $viewers = Role::query()
            ->where('guard_name', 'web')
            ->whereHas('permissions', fn ($q) => $q->whereIn('name', ['service_feedback.view', 'service_feedback.complaints.view']))
            ->get();
        $targets = Permission::query()->whereIn('name', ['service_feedback.view_employee_target', 'service_feedback.view_organization_target'])->get();

        foreach ($viewers as $role) {
            $role->givePermissionTo($targets);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The column stays text: narrowing it could truncate encrypted values.
    }
};
