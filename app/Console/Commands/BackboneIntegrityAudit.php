<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Core\BackboneIntegrityChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only checks of the master backbone chain
 * Organization -> unit -> position -> employee -> assignment -> card.
 * Nothing is changed; each finding names how to resolve it.
 */
class BackboneIntegrityAudit extends Command
{
    protected $signature = 'backbone:audit-integrity {--strict : Exit with a failure code when any finding is reported}';

    protected $description = 'Report broken links in the organization / position / employee / assignment / card chain (read-only)';

    public function handle(BackboneIntegrityChecks $checks): int
    {
        $counts = $checks->counts();
        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [BackboneIntegrityChecks::LABELS[$key], number_format($count)];
        }
        $rows[] = ['positions.organization_id foreign key present', $this->hasPositionOrganizationKey() ? 'yes' : 'no'];
        $this->table(['Check', 'Result'], $rows);

        $total = array_sum($counts);
        if ($total === 0) {
            $this->info('No broken links found.');
        } else {
            $this->warn('Findings are listed above. Repair them through the backbone screens or a reviewed data fix; card links: cards:audit-replacement-links --repair.');
        }

        return $total > 0 && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    private function hasPositionOrganizationKey(): bool
    {
        return collect(Schema::getForeignKeys('positions'))->contains(fn (array $key): bool => $key['columns'] === ['organization_id']);
    }
}
