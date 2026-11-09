<?php

declare(strict_types=1);

namespace App\Console\Commands\InstitutionOffices;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\InstitutionOffice;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrates legacy InstitutionOffice records to OrganizationUnit records.
 *
 * Safe to run multiple times (idempotent). Use --dry-run to preview without writing.
 */
class MigrateToOrganizationUnitsCommand extends Command
{
    protected $signature = 'institution-offices:migrate-to-organization-units
                            {--dry-run : Preview what would be migrated without writing anything}
                            {--actor-id= : User ID to record as the audit actor (defaults to first super-admin)}';

    protected $description = 'Migrate legacy institution_offices records to organization_units (idempotent)';

    public function handle(WriteAuditLogAction $auditLog, GenerateCodeAction $generateCode): int
    {
        if (! Schema::hasTable('institution_offices')) {
            $this->info('No institution_offices table found. Nothing to migrate.');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[DRY RUN] No changes will be written.');
        }

        $actor = $this->resolveActor();

        if ($actor === null) {
            $this->error('No actor user found. Create at least one user or pass --actor-id.');

            return self::FAILURE;
        }

        $migrated = 0;
        $alreadyMapped = 0;
        $skipped = 0;

        InstitutionOffice::query()->chunk(100, function ($offices) use (
            $isDryRun,
            $actor,
            $auditLog,
            $generateCode,
            &$migrated,
            &$alreadyMapped,
            &$skipped,
        ): void {
            foreach ($offices as $office) {
                // Check if already mapped via institution_office_id
                $existing = OrganizationUnit::query()
                    ->where('institution_office_id', $office->id)
                    ->first(['id', 'name_en', 'code']);

                if ($existing !== null) {
                    $this->line("  [SKIP] Already mapped: {$office->name_en} → org-unit {$existing->code}");
                    $alreadyMapped++;

                    continue;
                }

                // Resolve organization_id (institution_id on the old record)
                $organizationId = $office->institution_id ?? $office->structural_organization_id ?? null;

                if ($organizationId === null) {
                    $this->warn("  [SKIP] No organization_id for office: {$office->name_en} ({$office->id})");
                    $skipped++;

                    continue;
                }

                if ($isDryRun) {
                    $this->info("  [WOULD CREATE] OrganizationUnit from InstitutionOffice: {$office->name_en} (institution_office_id={$office->id})");
                    $migrated++;

                    continue;
                }

                try {
                    DB::transaction(function () use ($office, $organizationId, $actor, $auditLog, $generateCode, &$migrated): void {
                        $code = $generateCode->execute(
                            CodeRuleEntityType::OrganizationUnit,
                            ['organization_id' => $organizationId],
                            $actor,
                            $office->office_code ?? null,
                            'code',
                        );

                        $unit = OrganizationUnit::query()->create([
                            'organization_id' => $organizationId,
                            'institution_office_id' => $office->id,
                            'unit_type' => 'office',
                            'code' => $code,
                            'name_en' => $office->name_en,
                            'name_am' => $office->name_am ?? null,
                            'status' => $office->status ?? 'active',
                            'effective_from' => $office->opened_on ?? null,
                            'effective_to' => $office->closed_on ?? null,
                            'metadata' => [
                                'migrated_from' => 'institution_offices',
                                'institution_office_id' => $office->id,
                                'institution_office_legacy_source' => true,
                            ],
                            'created_by' => $actor->getKey(),
                            'updated_by' => $actor->getKey(),
                        ]);

                        $auditLog->execute(
                            AuditEventType::InstitutionOfficeMigratedToOrganizationUnit,
                            $actor,
                            $unit,
                            $organizationId,
                            newValues: [
                                'institution_office_id' => $office->id,
                                'institution_office_name' => $office->name_en,
                                'organization_unit_id' => $unit->id,
                                'organization_unit_code' => $unit->code,
                            ],
                        );

                        $migrated++;
                        $this->info("  [MIGRATED] {$office->name_en} → {$unit->code} ({$unit->id})");
                    });
                } catch (\Throwable $e) {
                    $this->error("  [ERROR] Failed to migrate {$office->name_en}: {$e->getMessage()}");
                }
            }
        });

        $this->newLine();

        if ($isDryRun) {
            $this->line("<fg=yellow>DRY RUN SUMMARY:</> Would migrate: {$migrated} | Already mapped: {$alreadyMapped} | Skipped: {$skipped}");
        } else {
            $this->line("<fg=green>MIGRATION COMPLETE:</> Migrated: {$migrated} | Already mapped: {$alreadyMapped} | Skipped: {$skipped}");
        }

        return self::SUCCESS;
    }

    private function resolveActor(): ?User
    {
        $actorId = $this->option('actor-id');

        if ($actorId !== null) {
            return User::query()->find($actorId);
        }

        // Default: first super-admin or any user
        return User::query()->first();
    }
}
