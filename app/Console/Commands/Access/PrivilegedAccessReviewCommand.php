<?php

declare(strict_types=1);

namespace App\Console\Commands\Access;

use App\Services\Security\PrivilegedAccessReview;
use Illuminate\Console\Command;

/**
 * Read-only privileged-account review (PrivilegedAccessReview). Machine
 * clients are reviewed by api:consumers and api:access-review.
 */
class PrivilegedAccessReviewCommand extends Command
{
    protected $signature = 'access:review {--days=90 : Sign-in age after which an active account is STALE} {--json : Print the report as JSON}';

    protected $description = 'Read-only: review accounts holding a city-wide or MFA-required role (last sign-in, MFA, scope)';

    public function handle(PrivilegedAccessReview $review): int
    {
        $rows = $review->rows(max(1, (int) $this->option('days')));

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No account holds a privileged role ('.implode(', ', $review->privilegedRoles()).').');

            return self::SUCCESS;
        }

        $this->table(
            ['User', 'Name', 'Status', 'Roles', 'Last sign-in', 'MFA', 'Organization scope', 'Findings'],
            array_map(fn (array $row): array => [
                $row['id'], $row['name'], $row['status'], implode(', ', $row['roles']), $row['last_sign_in'] ?? 'never',
                $row['mfa'], $row['organization_scope'], implode(', ', $row['findings']) ?: '—',
            ], $rows),
        );

        $flagged = count(array_filter($rows, fn (array $row): bool => $row['findings'] !== []));
        $this->line(count($rows).' privileged account(s); '.$flagged.' need a decision. Record the review outcome in the access-review log (docs/governance/security-governance.md).');

        return self::SUCCESS;
    }
}
