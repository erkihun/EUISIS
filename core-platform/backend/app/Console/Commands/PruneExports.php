<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExportRun;
use App\Services\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Deletes export files past their retention (ADR 0011); the export's record, filters and downloads stay. */
class PruneExports extends Command
{
    protected $signature = 'core:prune-exports';

    protected $description = 'Delete report export files past their retention';

    public function handle(): int
    {
        SystemHealth::recordRun('core:prune-exports');
        $removed = 0;
        ExportRun::query()->whereNotNull('file_path')->where('expires_at', '<', now())->orderBy('id')->each(function (ExportRun $run) use (&$removed): void {
            Storage::disk((string) $run->file_disk)->delete((string) $run->file_path);
            $run->forceFill(['file_path' => null, 'status' => 'expired'])->save();
            $removed++;
        });
        $this->info("{$removed} export file(s) deleted.");

        return self::SUCCESS;
    }
}
