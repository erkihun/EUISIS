<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use App\Models\IntelligenceDeployment;
use Illuminate\Console\Command;

/**
 * Records a release so incidents can be correlated with it (brief §44).
 * Call it from the release script after the symlink switch:
 *   php artisan intelligence:record-deployment --release=v1.8.0 --commit=$(git rev-parse HEAD)
 * Without options it reads APP_RELEASE / APP_COMMIT. Nothing is guessed from
 * the filesystem: no record, no correlation (NEEDS_DEPLOYMENT_CONFIGURATION).
 */
final class IntelligenceRecordDeployment extends Command
{
    protected $signature = 'intelligence:record-deployment
        {--release= : Release name or tag (defaults to APP_RELEASE)}
        {--commit= : Commit hash (defaults to APP_COMMIT)}
        {--change= : Change reference (ticket, approval)}
        {--notes= : Free note}';

    protected $description = 'Record a deployment for Intelligence deployment correlation';

    public function handle(): int
    {
        $release = trim((string) ($this->option('release') ?: config('intelligence.deployment.release')));
        $commit = trim((string) ($this->option('commit') ?: config('intelligence.deployment.commit')));

        if ($release === '') {
            $this->error('No release given and APP_RELEASE is not set; nothing recorded.');

            return self::FAILURE;
        }
        if ($commit !== '' && preg_match('/^[0-9a-f]{7,64}$/i', $commit) !== 1) {
            $this->error('The commit must be a hexadecimal hash.');

            return self::FAILURE;
        }

        $deployment = IntelligenceDeployment::query()->create([
            'release' => mb_substr($release, 0, 80),
            'commit_hash' => $commit === '' ? null : strtolower($commit),
            'environment' => (string) app()->environment(),
            'deployed_at' => now(),
            'change_reference' => $this->option('change') ? mb_substr((string) $this->option('change'), 0, 120) : null,
            'recorded_by' => 'artisan',
            'notes' => $this->option('notes') ? mb_substr((string) $this->option('notes'), 0, 2000) : null,
        ]);

        $this->info("Recorded deployment {$deployment->release} ({$deployment->environment}) at {$deployment->deployed_at->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
