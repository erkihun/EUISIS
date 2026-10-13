<?php

declare(strict_types=1);

namespace App\Console\Commands\Images;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use App\Support\EmployeePhotoStorage;
use App\Support\Images\ImageKind;
use App\Support\Images\ImageOptimizer;
use Closure;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Shrinks and re-encodes the images that were uploaded before uploads were
 * optimised: system logo, favicon and seal, institution logos, user profile
 * photos and employee photos. A file is rewritten only when the result is
 * smaller or downscaled; when its format changes, the owning record is pointed
 * at the new file and the old one removed. Safe to run again at any time.
 */
class OptimizeStoredImages extends Command
{
    protected $signature = 'images:optimize {--dry-run : Report what would change without writing anything}';

    protected $description = 'Shrink and re-encode previously uploaded logos, favicon, seal and photos for the web';

    private const SYSTEM_ASSET_KEYS = ['identity_system_logo', 'favicon', 'seal'];

    public function handle(ImageOptimizer $optimizer, SystemSettingsService $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $before = 0;
        $after = 0;
        $changed = 0;
        $settingsTouched = false;

        foreach ($this->targets() as [$label, $disk, $path, $kind, $apply]) {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                $rows[] = [$label, $path, '—', '—', 'missing file'];

                continue;
            }

            $absolute = $storage->path($path);
            $originalBytes = (int) filesize($absolute);
            $result = $optimizer->optimize($absolute, $kind);

            if ($result === null) {
                $rows[] = [$label, $path, $this->kb($originalBytes), $this->kb($originalBytes), 'kept as is'];
                $before += $originalBytes;
                $after += $originalBytes;

                continue;
            }

            $newPath = preg_replace('/\.[A-Za-z0-9]+$/', '', $path).'.'.$result->extension;

            if (! $dryRun) {
                $storage->put($newPath, (string) file_get_contents($result->path));

                if ($newPath !== $path) {
                    $apply($newPath);
                    $storage->delete($path);
                    $settingsTouched = $settingsTouched || str_starts_with($label, 'setting');
                }
            }

            $result->discard();
            $rows[] = [$label, $newPath, $this->kb($originalBytes), $this->kb($result->bytes), sprintf('%s %dx%d', $dryRun ? 'would write' : 'written', $result->width, $result->height)];
            $before += $originalBytes;
            $after += $result->bytes;
            $changed++;
        }

        if ($settingsTouched) {
            $settings->clearCache();
        }

        if ($rows === []) {
            $this->info('No uploaded images found.');

            return self::SUCCESS;
        }

        $this->table(['Owner', 'File', 'Before', 'After', 'Result'], $rows);
        $this->info(sprintf(
            '%d of %d images %s: %s -> %s.',
            $changed,
            count($rows),
            $dryRun ? 'would change' : 'changed',
            $this->kb($before),
            $this->kb($after),
        ));

        return self::SUCCESS;
    }

    /** @return Generator<int, array{0: string, 1: string, 2: string, 3: ImageKind, 4: Closure(string): void}> */
    private function targets(): Generator
    {
        $assets = SystemSetting::query()
            ->where('group', SystemSettingsRegistry::GROUP_GENERAL)
            ->whereIn('key', self::SYSTEM_ASSET_KEYS)
            ->get();

        foreach ($assets as $setting) {
            $path = $setting->value;

            if (! is_string($path) || $path === '') {
                continue;
            }

            yield [
                'setting '.$setting->key,
                'public',
                $path,
                $setting->key === 'favicon' ? ImageKind::Favicon : ImageKind::Logo,
                function (string $newPath) use ($setting): void {
                    $setting->value = $newPath;
                    $setting->saveQuietly();
                },
            ];
        }

        foreach (Organization::query()->whereNotNull('logo_path')->cursor() as $organization) {
            yield [
                'organization '.$organization->code,
                'public',
                (string) $organization->logo_path,
                ImageKind::Logo,
                fn (string $newPath) => $organization->forceFill(['logo_path' => $newPath])->saveQuietly(),
            ];
        }

        foreach (User::query()->whereNotNull('profile_photo_path')->cursor() as $user) {
            yield [
                'user '.$user->id,
                'public',
                (string) $user->profile_photo_path,
                ImageKind::Photo,
                fn (string $newPath) => $user->forceFill(['profile_photo_path' => $newPath])->saveQuietly(),
            ];
        }

        foreach (Employee::query()->whereNotNull('photo_path')->cursor() as $employee) {
            $path = (string) $employee->photo_path;

            yield [
                'employee '.$employee->employee_number,
                EmployeePhotoStorage::isPrivate($path) ? EmployeePhotoStorage::PRIVATE_DISK : 'public',
                $path,
                ImageKind::Photo,
                fn (string $newPath) => $employee->forceFill(['photo_path' => $newPath])->saveQuietly(),
            ];
        }
    }

    private function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 1).' KB';
    }
}
