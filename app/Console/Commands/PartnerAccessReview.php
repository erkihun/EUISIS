<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Integration\PartnerAccessReviewService;
use Illuminate\Console\Command;

/**
 * Read-only report of partner applications whose access needs a decision
 * (UNREVIEWED, UNSCOPED, OVERPRIVILEGED, LEGACY_CREDENTIAL, PROVIDER_UNBOUND).
 * Prints codes and finding names only — never tokens or personal data.
 */
class PartnerAccessReview extends Command
{
    protected $signature = 'api:access-review {--json : Print the report as JSON}';

    protected $description = 'List partner applications whose organization access or credentials need review';

    public function handle(PartnerAccessReviewService $review): int
    {
        $rows = $review->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows->values()->all(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->info('No partner applications are registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Status', 'Organization access', 'Grants', 'Findings'],
            $rows->map(fn (array $row): array => [$row['code'], $row['status'], $row['organization_access'], $row['grants'], implode(', ', $row['findings']) ?: '—'])->all(),
        );

        $flagged = $rows->filter(fn (array $row): bool => $row['findings'] !== [])->count();
        $this->line($flagged.' of '.$rows->count().' application(s) need review.');

        return self::SUCCESS;
    }
}
