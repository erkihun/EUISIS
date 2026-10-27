<?php

declare(strict_types=1);

use App\Support\CorePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Granular permissions (ADR 0009): roles become rows with their permissions.
 * The three roles accounts already hold (users.role) are created as system
 * roles with the permissions they had, plus the finer permissions that split
 * what one coarse permission used to allow (card steps, assignments,
 * hierarchy governance, imports, roles), so no account loses or gains a
 * capability by the change. Account role keys are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique('core_roles_key_unique');
            $table->string('name_en', 120);
            $table->string('name_am', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('scope_type', 16)->default('scoped');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::create('core_role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('core_role_id')->constrained('core_roles')->cascadeOnDelete();
            $table->string('permission', 100);
            $table->unique(['core_role_id', 'permission'], 'core_role_permissions_unique');
            $table->index(['permission'], 'core_role_permissions_permission_idx');
        });

        foreach (CorePermissions::systemRoles() as $key => [$nameEn, $nameAm, $permissions]) {
            $id = DB::table('core_roles')->insertGetId([
                'key' => $key, 'name_en' => $nameEn, 'name_am' => $nameAm, 'scope_type' => 'scoped', 'is_system' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('core_role_permissions')->insert(array_map(fn (string $permission): array => ['core_role_id' => $id, 'permission' => $permission],
                array_values(array_unique($permissions))));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('core_role_permissions');
        Schema::dropIfExists('core_roles');
    }
};
