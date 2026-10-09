<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Position;
use App\Services\Vacancy\PositionCapacityService;
use Illuminate\Console\Command;

class BackfillPositionOccupancies extends Command
{
    protected $signature = 'positions:backfill-occupancies
                            {--dry-run : Print what would be done without making any changes}
                            {--position= : Only backfill the position with this UUID}';

    protected $description = 'Backfill position_occupancies from existing current assignments for approved establishments.';

    public function handle(PositionCapacityService $capacityService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $positionId = $this->option('position');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be written.');
        }

        $query = Position::query()->where('is_active', true);

        if ($positionId !== null) {
            $query->where('id', $positionId);
        }

        $total = 0;
        $skipped = 0;
        $created = 0;

        $query->chunk(100, function ($positions) use ($capacityService, $dryRun, &$total, &$skipped, &$created): void {
            foreach ($positions as $position) {
                $total++;
                $result = $capacityService->backfillOccupanciesForPosition($position, $dryRun);

                if ($result['skipped'] ?? false) {
                    $skipped++;
                    $this->line("  SKIP  {$position->id} ({$position->title_en}) — {$result['reason']}");
                } else {
                    $count = $result['count'];
                    $created += $count;
                    if ($count > 0) {
                        $prefix = $dryRun ? 'WOULD CREATE' : 'CREATED';
                        $this->info("  {$prefix}  {$count} occupancy record(s) for position {$position->id} ({$position->title_en})");
                    }
                }
            }
        });

        $this->newLine();
        $this->line("Processed: {$total} positions | Skipped: {$skipped} | Occupancies ".($dryRun ? 'would be created' : 'created').": {$created}");

        return self::SUCCESS;
    }
}
