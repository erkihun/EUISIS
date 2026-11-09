<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Support\EmployeePhotoStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moves employee photos from the public disk to private storage (SEC-013).
 *
 * Photos uploaded before the fix sit under storage/app/public, reachable at
 * /storage/... by anyone holding the URL. Each is copied to
 * employee-photos/{uuid}.{ext} on the private disk, the record is pointed at
 * the copy, and only then is the public file deleted, so a failure midway
 * never leaves a record without its photo. Safe to re-run.
 */
class PrivatizeEmployeePhotos extends Command
{
    protected $signature = 'employees:privatize-photos {--dry-run : Report what would move without changing anything}';

    protected $description = 'Move employee photos from the public disk to private storage';

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk(EmployeePhotoStorage::PRIVATE_DISK);
        $moved = 0;
        $skipped = 0;

        Employee::query()
            ->whereNotNull('photo_path')
            ->where('photo_path', 'not like', EmployeePhotoStorage::PREFIX.'%')
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($public, $private, &$moved, &$skipped): void {
                foreach ($employees as $employee) {
                    $source = (string) $employee->photo_path;
                    $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));

                    if (str_contains($source, '..') || ! in_array($extension, self::EXTENSIONS, true) || ! $public->exists($source)) {
                        $skipped++;

                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $moved++;

                        continue;
                    }

                    $target = EmployeePhotoStorage::PREFIX.Str::uuid().'.'.$extension;
                    if (! $private->put($target, $public->get($source))) {
                        $skipped++;

                        continue;
                    }

                    DB::transaction(fn () => $employee->forceFill(['photo_path' => $target])->saveQuietly());
                    $public->delete($source);
                    $moved++;
                }
            });

        $this->info(($this->option('dry-run') ? 'Would move' : 'Moved')." {$moved} photo(s); skipped {$skipped}.");

        return self::SUCCESS;
    }
}
