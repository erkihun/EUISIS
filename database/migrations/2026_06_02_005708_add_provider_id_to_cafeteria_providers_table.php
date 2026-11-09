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
        if (! Schema::hasColumn('cafeteria_providers', 'provider_id')) {
            Schema::table('cafeteria_providers', function (Blueprint $table): void {
                $table->foreignUuid('provider_id')
                    ->nullable()
                    ->after('service_provider_id')
                    ->constrained('providers')
                    ->nullOnDelete();

                $table->unique('provider_id');
            });
        }

        $cafeteriaProviderTypeId = DB::table('provider_types')->where('code', 'CAFETERIA')->value('id');
        $cafeteriaServiceTypeId = DB::table('service_types')->where('code', 'cafeteria')->value('id');

        if (! $cafeteriaProviderTypeId || ! $cafeteriaServiceTypeId) {
            return;
        }

        $now = now();

        DB::table('cafeteria_providers')
            ->whereNull('provider_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $cafeteriaProvider) use ($cafeteriaProviderTypeId, $cafeteriaServiceTypeId, $now): void {
                $providerId = (string) Str::uuid7();

                DB::table('providers')->insert([
                    'id' => $providerId,
                    'provider_code' => $cafeteriaProvider->code,
                    'provider_type_id' => $cafeteriaProviderTypeId,
                    'name_en' => $cafeteriaProvider->name_en,
                    'name_am' => $cafeteriaProvider->name_am,
                    'assigned_organization_id' => $cafeteriaProvider->organization_id,
                    'assigned_scope_type' => $cafeteriaProvider->assigned_scope_type ?? 'self',
                    'contact_person' => $cafeteriaProvider->contact_person,
                    'phone_number' => $cafeteriaProvider->phone_number,
                    'email' => $cafeteriaProvider->email,
                    'address' => $cafeteriaProvider->location,
                    'status' => $cafeteriaProvider->is_active ? 'active' : 'inactive',
                    'metadata' => $cafeteriaProvider->metadata,
                    'created_by' => $cafeteriaProvider->created_by,
                    'updated_by' => $cafeteriaProvider->updated_by,
                    'created_at' => $cafeteriaProvider->created_at ?? $now,
                    'updated_at' => $cafeteriaProvider->updated_at ?? $now,
                ]);

                DB::table('provider_services')->insert([
                    'id' => (string) Str::uuid7(),
                    'provider_id' => $providerId,
                    'service_type_id' => $cafeteriaServiceTypeId,
                    'status' => $cafeteriaProvider->is_active ? 'active' : 'inactive',
                    'enabled_at' => $cafeteriaProvider->created_at ?? $now,
                    'created_by' => $cafeteriaProvider->created_by,
                    'updated_by' => $cafeteriaProvider->updated_by,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('cafeteria_providers')
                    ->where('id', $cafeteriaProvider->id)
                    ->update(['provider_id' => $providerId]);
            });

        if (! Schema::hasTable('cafeteria_provider_users') || ! Schema::hasTable('provider_users')) {
            return;
        }

        DB::table('cafeteria_provider_users')
            ->join('cafeteria_providers', 'cafeteria_provider_users.cafeteria_provider_id', '=', 'cafeteria_providers.id')
            ->whereNotNull('cafeteria_providers.provider_id')
            ->select([
                'cafeteria_provider_users.*',
                'cafeteria_providers.provider_id as general_provider_id',
            ])
            ->orderBy('cafeteria_provider_users.id')
            ->get()
            ->each(function (object $legacyUser) use ($now): void {
                if (! $legacyUser->email && ! $legacyUser->username) {
                    return;
                }

                $exists = DB::table('provider_users')
                    ->where(function ($query) use ($legacyUser): void {
                        if ($legacyUser->email) {
                            $query->orWhere('email', $legacyUser->email);
                        }

                        if ($legacyUser->username) {
                            $query->orWhere('username', $legacyUser->username);
                        }
                    })
                    ->exists();

                if ($exists) {
                    return;
                }

                DB::table('provider_users')->insert([
                    'id' => (string) Str::uuid7(),
                    'provider_id' => $legacyUser->general_provider_id,
                    'name' => $legacyUser->name,
                    'email' => $legacyUser->email,
                    'username' => $legacyUser->username,
                    'phone_number' => $legacyUser->phone_number,
                    'password' => $legacyUser->password,
                    'provider_role' => 'operator',
                    'status' => $legacyUser->status,
                    'portal_enabled' => $legacyUser->portal_enabled,
                    'must_change_password' => $legacyUser->must_change_password,
                    'last_login_at' => $legacyUser->last_login_at,
                    'last_login_ip' => $legacyUser->last_login_ip,
                    'email_verified_at' => $legacyUser->email_verified_at,
                    'remember_token' => $legacyUser->remember_token,
                    'created_by' => $legacyUser->created_by,
                    'updated_by' => $legacyUser->updated_by,
                    'suspended_by' => $legacyUser->suspended_by,
                    'suspended_at' => $legacyUser->suspended_at,
                    'suspension_reason' => $legacyUser->suspension_reason,
                    'metadata' => $legacyUser->metadata,
                    'created_at' => $legacyUser->created_at ?? $now,
                    'updated_at' => $legacyUser->updated_at ?? $now,
                    'deleted_at' => $legacyUser->deleted_at ?? null,
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cafeteria_providers', 'provider_id')) {
            return;
        }

        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_id');
        });
    }
};
