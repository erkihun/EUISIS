<?php

declare(strict_types=1);

namespace App\Console\Commands\Organizations;

use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Detects organizations that look like institution offices (physical branches)
 * rather than legal/geographic entities. These should typically be Institution Offices.
 *
 * Patterns checked:
 * - Name contains keywords like "Office", "Branch", "Center", "Service Center", "Outlet", "Post"
 *   (but not "Head Office" at bureau level which can be either)
 * - Name ends with common branch suffixes like "Office", "Branch"
 */
class DetectOfficeLikeOrganizations extends Command
{
    protected $signature = 'organizations:detect-office-like-organizations
                            {--limit=100 : Maximum number of results to show}
                            {--format=table : Output format: table or json}';

    protected $description = 'Detect organizations that look like institution offices or branches (and should be in Institution Offices instead)';

    /** Keywords in the name that suggest an institution office/branch. */
    private const NAME_PATTERNS = [
        ' Branch',
        ' Office',
        ' Sub-office',
        ' Sub Office',
        ' Service Center',
        ' Service Centre',
        ' Outlet',
        ' Hub',
        ' Desk',
        ' Post',
    ];

    /** Organization type codes that suggest an office-level classification. */
    private const TYPE_CODE_PATTERNS = [
        'branch',
        'office',
        'service_center',
        'service_centre',
        'outlet',
        'hub',
    ];

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $format = $this->option('format');

        $this->info('Scanning organizations for office/branch patterns...');

        $query = Organization::query()->with('type:id,code,name_en');

        $query->where(function ($q): void {
            foreach (self::NAME_PATTERNS as $pattern) {
                $q->orWhere('name_en', ci_like_operator(), "%{$pattern}%")
                    ->orWhere('name_am', ci_like_operator(), "%{$pattern}%");
            }
        });

        $suspicious = $query->limit($limit)->get(['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id']);

        // Also find organizations with office-like types
        $byType = Organization::query()
            ->with('type:id,code,name_en')
            ->whereHas('type', function ($q): void {
                foreach (self::TYPE_CODE_PATTERNS as $pattern) {
                    $q->orWhere('code', ci_like_operator(), "%{$pattern}%");
                }
            })
            ->limit($limit)
            ->get(['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id']);

        $all = $suspicious->merge($byType)->unique('id');

        if ($all->isEmpty()) {
            $this->info('No office-like organizations detected.');

            return self::SUCCESS;
        }

        $this->warn("Found {$all->count()} potentially office-like organization(s):");

        if ($format === 'json') {
            $this->line($all->map(fn ($o) => [
                'id' => $o->id,
                'code' => $o->code,
                'name_en' => $o->name_en,
                'name_am' => $o->name_am,
                'status' => $o->status instanceof \BackedEnum ? $o->status->value : (string) $o->status,
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

        $this->warn('These organizations may belong in the Institution Offices module.');
        $this->line('Review each entry and consider creating a corresponding Institution Office record.');
        $this->line('Institution Offices represent physical branches of an institution at a geographic level.');

        return self::SUCCESS;
    }
}
