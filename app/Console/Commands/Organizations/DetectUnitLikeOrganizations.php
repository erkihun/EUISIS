<?php

declare(strict_types=1);

namespace App\Console\Commands\Organizations;

use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Detects organizations that look like internal departments or teams rather than
 * legal/geographic entities. These should typically be Organization Units instead.
 *
 * Patterns checked:
 * - Name contains keywords like "Department", "Directorate", "Team", "Division", "Section", "Unit", "Office" (but not "Bureau")
 * - Organization type code contains "unit", "department", "team", "directorate", "division"
 */
class DetectUnitLikeOrganizations extends Command
{
    protected $signature = 'organizations:detect-unit-like-organizations
                            {--limit=100 : Maximum number of results to show}
                            {--format=table : Output format: table or json}';

    protected $description = 'Detect organizations that look like departments or teams (and should likely be Organization Units instead)';

    /** Keywords in the name that suggest a unit-level concept (case-insensitive). */
    private const NAME_PATTERNS = [
        'Directorate',
        'Department',
        'Division',
        'Section',
        'Work Unit',
        'Team',
        'Desk',
        'Case Team',
    ];

    /** Organization type codes that suggest unit-level classification. */
    private const TYPE_CODE_PATTERNS = [
        'directorate',
        'department',
        'division',
        'team',
        'section',
        'unit',
        'work_unit',
    ];

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $format = $this->option('format');

        $this->info('Scanning organizations for unit-like patterns...');

        $query = Organization::query()->with('type:id,code,name_en');

        // Match by name patterns
        $query->where(function ($q): void {
            foreach (self::NAME_PATTERNS as $pattern) {
                $q->orWhere('name_en', ci_like_operator(), "%{$pattern}%")
                    ->orWhere('name_am', ci_like_operator(), "%{$pattern}%");
            }
        });

        $suspicious = $query->limit($limit)->get(['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id']);

        // Also find organizations with unit-like types
        $typeQuery = Organization::query()
            ->with('type:id,code,name_en')
            ->whereHas('type', function ($q): void {
                foreach (self::TYPE_CODE_PATTERNS as $pattern) {
                    $q->orWhere('code', ci_like_operator(), "%{$pattern}%");
                }
            })
            ->limit($limit);

        $byType = $typeQuery->get(['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id']);

        // Merge and deduplicate
        $all = $suspicious->merge($byType)->unique('id');

        if ($all->isEmpty()) {
            $this->info('No unit-like organizations detected.');

            return self::SUCCESS;
        }

        $this->warn("Found {$all->count()} potentially unit-like organization(s):");

        if ($format === 'json') {
            $this->line($all->map(fn ($o) => [
                'id' => $o->id,
                'code' => $o->code,
                'name_en' => $o->name_en,
                'name_am' => $o->name_am,
                'status' => $o->status,
                'type_code' => $o->type?->code,
                'type_name' => $o->type?->name_en,
            ])->toJson(JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['ID', 'Code', 'Name (EN)', 'Status', 'Type'],
                $all->map(fn ($o) => [
                    substr($o->id, 0, 8).'...',
                    $o->code,
                    $o->name_en,
                    $o->status instanceof \BackedEnum ? $o->status->value : (string) $o->status,
                    $o->type?->name_en ?? '-',
                ])->toArray(),
            );
        }

        $this->warn('These organizations may belong in the Organization Units module.');
        $this->line('Review each entry and consider migrating to Organization Units if appropriate.');

        return self::SUCCESS;
    }
}
