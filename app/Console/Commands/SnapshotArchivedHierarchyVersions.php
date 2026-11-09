<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Services\Hierarchy\HierarchyVersionSnapshotService;
use Illuminate\Console\Command;

class SnapshotArchivedHierarchyVersions extends Command
{
    protected $signature = 'hierarchy:snapshot-archived';

    protected $description = 'Record the structure and counts of archived hierarchy versions that were archived before snapshots existed';

    public function handle(HierarchyVersionSnapshotService $snapshots): int
    {
        $versions = HierarchyVersion::query()->where('status', HierarchyVersionStatus::Archived)->whereNull('archive_snapshot')->get();
        $versions->each(fn (HierarchyVersion $version) => $snapshots->capture($version));

        $this->info("Hierarchy versions recorded: {$versions->count()}.");

        return self::SUCCESS;
    }
}
