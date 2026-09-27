<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 32);
            $table->string('status', 16);
            // Null only for LOGICAL_BACKUP, which is written outside the pgBackRest repositories.
            $table->unsignedSmallInteger('repository')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->string('failure_summary')->nullable();
            $table->string('backup_reference', 40)->nullable();
            $table->unsignedBigInteger('backup_size')->nullable();
            $table->timestampTz('recovery_target')->nullable();
            $table->string('source')->default('infrastructure');
            $table->timestampsTz();
            $table->index(['type', 'started_at']);
        });
        Schema::create('backup_restore_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('production_authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('restore_type', 24);
            $table->string('incident_reference', 80);
            $table->text('reason');
            $table->string('evidence_reference', 80)->nullable();
            $table->timestampTz('target_time')->nullable();
            $table->string('backup_reference', 40)->nullable();
            $table->string('status', 32)->default('REQUESTED')->index();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('failure_summary')->nullable();
            $table->timestampsTz();
        });
        foreach (PermissionCatalog::all() as $name => $entry) {
            if (str_starts_with($name, 'backups.')) {
                $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], $entry);
                foreach (Role::query()->where('guard_name', 'web')->whereIn('name', ['Super Admin', 'System Admin'])->get() as $role) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restore_requests');
        Schema::dropIfExists('backup_operations');
        Permission::query()->where('name', 'like', 'backups.%')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
