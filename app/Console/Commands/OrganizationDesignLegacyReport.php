<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\OrganizationDesign\LegacyMappingService;
use Illuminate\Console\Command;

/**
 * Read-only report of how the records that pre-date the organization design
 * map to it (docs/organization-design-legacy-mapping.md). Changes nothing.
 */
class OrganizationDesignLegacyReport extends Command
{
    protected $signature = 'organization-design:legacy-report {--organization= : One organization id; every active organization otherwise} {--json : Machine-readable output}';

    protected $description = 'Classify legacy units, positions and position services as FULLY_MAPPED / PARTIALLY_MAPPED / UNMAPPED_LEGACY / INVALID_REFERENCE';

    public function handle(LegacyMappingService $legacy): int
    {
        $organizations = Organization::query()->orderBy('code')
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('status', 'active'))
            ->get(['id', 'code', 'name_en']);
        if ($organizations->isEmpty()) {
            $this->error('No organization matched.');

            return self::FAILURE;
        }
        $rows = [];
        $all = [];
        foreach ($organizations as $organization) {
            $report = $legacy->classify($organization->id);
            foreach (['units', 'positions', 'position_services'] as $kind) {
                $s = $report['summary'][$kind];
                $rows[] = [$organization->code, $kind, array_sum($s), $s['FULLY_MAPPED'], $s['PARTIALLY_MAPPED'], $s['UNMAPPED_LEGACY'], $s['INVALID_REFERENCE']];
                $all[$organization->code][$kind] = $s;
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->table(['Organization', 'Records', 'Total', 'FULLY_MAPPED', 'PARTIALLY_MAPPED', 'UNMAPPED_LEGACY', 'INVALID_REFERENCE'], $rows);
        $this->line('INVALID_REFERENCE rows need a person (NEEDS_DATA_REVIEW); nothing was changed.');

        return self::SUCCESS;
    }
}
