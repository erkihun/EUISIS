<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\ProviderUser;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The old /provider-users page saved accounts to `service_provider_users`,
 * which no sign-in uses. This copies each of them to `provider_users` (the
 * provider portal) when its provider can be told unambiguously, from its
 * service provider or its cafeteria assignments, and lists the rest as
 * NEEDS_DECISION. Report only unless --apply; safe to run again.
 */
class MigrateLegacyProviderUsers extends Command
{
    protected $signature = 'provider-users:migrate-legacy {--apply : Create the portal accounts (default: report only)}';

    protected $description = 'Copy legacy service_provider_users accounts to provider portal accounts';

    public function handle(WriteAuditLogAction $audit): int
    {
        if (! Schema::hasTable('service_provider_users') || ! Schema::hasColumn('service_provider_users', 'password')) {
            $this->info(__('provider-users.legacy.none'));

            return self::SUCCESS;
        }

        $legacy = DB::table('service_provider_users')->whereNotNull('password')->orderBy('created_at')->get();

        if ($legacy->isEmpty()) {
            $this->info(__('provider-users.legacy.none'));

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $counts = ['migrated' => 0, 'skipped' => 0, 'decisions' => 0];

        foreach ($legacy as $row) {
            $label = $row->email ?: ($row->username ?: $row->id);

            if (blank($row->email) && blank($row->username)) {
                $this->warn(__('provider-users.legacy.needs_decision', ['email' => $label, 'reason' => __('provider-users.legacy.no_sign_in')]));
                $counts['decisions']++;

                continue;
            }

            if ($this->alreadyMigrated($row)) {
                continue;
            }

            if ($this->signInTaken($row)) {
                $this->line(__('provider-users.legacy.skipped_existing', ['email' => $label]));
                $counts['skipped']++;

                continue;
            }

            $providers = $this->candidateProviders($row);

            if ($providers->count() !== 1) {
                $reason = $providers->isEmpty() ? 'no_provider' : 'ambiguous_provider';
                $this->warn(__('provider-users.legacy.needs_decision', ['email' => $label, 'reason' => __("provider-users.legacy.{$reason}")]));
                $counts['decisions']++;

                continue;
            }

            if ($apply) {
                $this->migrate($row, (string) $providers->first(), $audit);
            }

            $this->info(__('provider-users.legacy.migrated', ['email' => $label]));
            $counts['migrated']++;
        }

        $this->newLine();
        $this->info(__('provider-users.legacy.summary', $counts));

        if (! $apply) {
            $this->comment(__('provider-users.legacy.dry_run'));
        }

        return self::SUCCESS;
    }

    private function alreadyMigrated(object $row): bool
    {
        return ProviderUser::withTrashed()->where('metadata->legacy_service_provider_user_id', $row->id)->exists();
    }

    private function signInTaken(object $row): bool
    {
        return ProviderUser::withTrashed()
            ->where(function ($query) use ($row): void {
                if (filled($row->email)) {
                    $query->orWhere('email', $row->email);
                }
                if (filled($row->username)) {
                    $query->orWhere('username', $row->username);
                }
            })
            ->exists();
    }

    /** @return Collection<int, string> */
    private function candidateProviders(object $row): Collection
    {
        $ids = collect();

        if (filled($row->service_provider_id ?? null) && Schema::hasColumn('cafeteria_providers', 'service_provider_id')) {
            $ids = $ids->merge(DB::table('cafeteria_providers')
                ->where('service_provider_id', $row->service_provider_id)
                ->whereNotNull('provider_id')
                ->pluck('provider_id'));
        }

        if (Schema::hasTable('cafeteria_provider_assignments') && Schema::hasColumn('cafeteria_provider_assignments', 'service_provider_user_id')) {
            $ids = $ids->merge(DB::table('cafeteria_provider_assignments')
                ->join('cafeteria_providers', 'cafeteria_providers.id', '=', 'cafeteria_provider_assignments.cafeteria_provider_id')
                ->where('cafeteria_provider_assignments.service_provider_user_id', $row->id)
                ->where('cafeteria_provider_assignments.is_active', true)
                ->whereNotNull('cafeteria_providers.provider_id')
                ->pluck('cafeteria_providers.provider_id'));
        }

        return $ids->map(fn ($id): string => (string) $id)->unique()->values();
    }

    private function migrate(object $row, string $providerId, WriteAuditLogAction $audit): void
    {
        DB::transaction(function () use ($row, $providerId, $audit): void {
            $id = (string) Str::uuid7();
            $metadata = json_decode((string) ($row->metadata ?? ''), true);
            $status = in_array($row->status, ['active', 'inactive', 'suspended'], true) ? $row->status : 'inactive';

            // The stored hash is copied as is (never re-hashed or read).
            DB::table('provider_users')->insert([
                'id' => $id,
                'provider_id' => $providerId,
                'name' => $row->name ?: ($row->username ?: $row->email),
                'email' => $row->email,
                'username' => $row->username,
                'phone_number' => $row->phone_number,
                'password' => $row->password,
                'provider_role' => 'operator',
                'status' => $status,
                'portal_enabled' => (bool) $row->portal_enabled,
                // An administrator chose this password: the holder replaces it.
                'must_change_password' => true,
                'created_by' => $row->created_by,
                'updated_by' => $row->updated_by,
                'suspended_by' => $status === 'suspended' ? $row->suspended_by : null,
                'suspended_at' => $status === 'suspended' ? $row->suspended_at : null,
                'suspension_reason' => $status === 'suspended' ? $row->suspension_reason : null,
                'metadata' => json_encode([...(is_array($metadata) ? $metadata : []), 'legacy_service_provider_user_id' => $row->id]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->execute(AuditEventType::ProviderUserCreated, null, ProviderUser::query()->find($id), null,
                newValues: ['provider_id' => $providerId, 'legacy_service_provider_user_id' => $row->id], reason: 'legacy_service_provider_user_migration');
        });
    }
}
