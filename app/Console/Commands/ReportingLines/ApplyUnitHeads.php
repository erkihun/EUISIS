<?php

declare(strict_types=1);

namespace App\Console\Commands\ReportingLines;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Positions\UpdatePositionAction;
use App\Enums\AuditEventType;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * The remediation path for units without a head (ADR 0025): a CSV written by
 * HR names which position leads which unit, and this command applies it
 * through the same action the position form uses (one head per unit, the
 * row lock, the audit log). Nothing is inferred from titles; a row that
 * does not match exactly one organization, unit and position is skipped
 * and reported. `--dry-run` only reports.
 *
 * CSV columns: organization_code, unit_code, job_position_code
 */
class ApplyUnitHeads extends Command
{
    protected $signature = 'reporting:apply-unit-heads
        {file : CSV with organization_code, unit_code, job_position_code}
        {--actor= : Email of the user recorded as the actor (required unless --dry-run)}
        {--dry-run : Report what would change without saving}';

    protected $description = 'Mark unit head positions from an HR-written CSV, through the position update action (ADR 0025)';

    public function __construct(
        private readonly UpdatePositionAction $update,
        private readonly WriteAuditLogAction $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $actor = null;
        if (! $dryRun) {
            $actor = User::query()->where('email', (string) $this->option('actor'))->first();
            if ($actor === null) {
                $this->error('--actor must name an existing user (email) when not a dry run.');

                return self::FAILURE;
            }
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Cannot open {$path}");

            return self::FAILURE;
        }
        $header = fgetcsv($handle);
        $columns = array_map(fn ($c): string => strtolower(trim((string) $c)), $header ?: []);
        foreach (['organization_code', 'unit_code', 'job_position_code'] as $required) {
            if (! in_array($required, $columns, true)) {
                $this->error("Missing column {$required}");

                return self::FAILURE;
            }
        }

        $applied = 0;
        $skipped = 0;
        $rows = [];
        $line = 1;
        while (($record = fgetcsv($handle)) !== false) {
            $line++;
            $row = array_combine($columns, array_pad(array_map('trim', $record), count($columns), ''));
            $result = $this->applyRow($row, $actor, $dryRun);
            $rows[] = [$line, $row['organization_code'], $row['unit_code'], $row['job_position_code'], $result];
            if (str_starts_with($result, 'ok')) {
                $applied++;
            } else {
                $skipped++;
            }
        }
        fclose($handle);

        $this->table(['Line', 'Organization', 'Unit', 'Position', 'Result'], $rows);
        $this->info($dryRun ? __('reporting-lines.commands.dry_run') : __('reporting-lines.commands.applied', ['count' => $applied, 'skipped' => $skipped]));

        return $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, string> $row */
    private function applyRow(array $row, ?User $actor, bool $dryRun): string
    {
        $organization = Organization::query()->where('code', $row['organization_code'])->first();
        if ($organization === null) {
            return 'skipped: organization not found';
        }
        $unit = OrganizationUnit::query()->where('organization_id', $organization->getKey())->where('code', $row['unit_code'])->first();
        if ($unit === null) {
            return 'skipped: unit not found in that organization';
        }
        $positions = Position::query()->where('organization_id', $organization->getKey())->where('job_position_code', $row['job_position_code'])->get();
        if ($positions->count() !== 1) {
            return $positions->isEmpty() ? 'skipped: position not found in that organization' : 'skipped: position code is not unique in that organization';
        }
        $position = $positions->first();
        if ((string) $position->organization_unit_id !== (string) $unit->getKey()) {
            return 'skipped: position is not placed in that unit';
        }
        if ($position->is_unit_head) {
            return 'ok: already the head';
        }
        $existing = Position::query()->where('organization_unit_id', $unit->getKey())->where('is_unit_head', true)->first();
        if ($existing !== null) {
            return 'skipped: unit already has head '.$existing->job_position_code;
        }
        if ($dryRun) {
            return 'ok: would mark as head';
        }
        try {
            $this->update->execute($position, ['is_unit_head' => true], $actor);
            $this->audit->execute(AuditEventType::UnitHeadApplied, $actor, $position, (string) $organization->getKey(), newValues: ['unit_id' => (string) $unit->getKey(), 'source' => 'reporting:apply-unit-heads']);
        } catch (ValidationException $e) {
            return 'skipped: '.implode('; ', array_map(fn (array $m): string => implode(' ', $m), $e->errors()));
        }

        return 'ok: marked as head';
    }
}
