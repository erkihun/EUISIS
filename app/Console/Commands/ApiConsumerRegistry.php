<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExternalApplication;
use App\Services\Integration\PartnerAccessReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The API consumer registry (docs/governance/api-lifecycle.md): every
 * registered application with its owner, version, scopes, organization bounds,
 * activity and credential expiry — the list read before any API change for
 * impact analysis. Read-only; prints codes and names, never tokens.
 *
 * Findings beyond api:access-review: NEVER_USED, STALE (no call within the
 * review window), NO_TOKEN_EXPIRY (a token that never expires).
 */
class ApiConsumerRegistry extends Command
{
    protected $signature = 'api:consumers {--days=90 : Days without a call after which an active application is STALE} {--json : Print the registry as JSON}';

    protected $description = 'Read-only: the API consumer registry (owner, version, scopes, organization bounds, last use, credential expiry)';

    public function handle(PartnerAccessReviewService $review): int
    {
        $cutoff = Carbon::now()->subDays(max(1, (int) $this->option('days')));
        // A token without its own expiry lives the default lifetime (TD-16, AppServiceProvider).
        $lifetime = config('sanctum.expiration') ?? config('sanctum.default_token_lifetime_minutes');

        $rows = ExternalApplication::query()
            ->withCount('organizationScopes')
            ->with('tokens:id,tokenable_id,tokenable_type,abilities,created_at,expires_at')
            ->orderBy('code')
            ->get()
            ->map(function (ExternalApplication $application) use ($review, $cutoff, $lifetime): array {
                $expiries = $application->tokens->map(fn ($token): ?Carbon => $token->expires_at
                    ?? ($lifetime ? $token->created_at?->copy()->addMinutes((int) $lifetime) : null));
                $findings = $review->findings($application);

                if ($application->isActive()) {
                    if ($application->last_used_at === null) {
                        $findings[] = 'NEVER_USED';
                    } elseif ($application->last_used_at->lt($cutoff)) {
                        $findings[] = 'STALE';
                    }
                }
                if ($expiries->contains(null)) {
                    $findings[] = 'NO_TOKEN_EXPIRY';
                }

                return [
                    'code' => $application->code,
                    'name' => $application->name,
                    'owner_institution' => $application->owner_institution,
                    'contact_person' => $application->contact_person,
                    'status' => $application->status,
                    'api_version' => 'v1',
                    'environment' => app()->environment(),
                    'scopes' => array_values($application->allowed_scopes ?? []),
                    'organization_access' => $application->organization_access,
                    'organization_grants' => (int) $application->organization_scopes_count,
                    'service_provider_bound' => $application->service_provider_id !== null,
                    'rate_limit_per_minute' => $application->rate_limit_per_minute,
                    'last_used_at' => $application->last_used_at?->toIso8601String(),
                    'tokens' => $application->tokens->count(),
                    'next_token_expiry' => $expiries->filter()->sort()->first()?->toIso8601String(),
                    'findings' => array_values(array_unique($findings)),
                ];
            });

        if ($this->option('json')) {
            $this->line((string) json_encode($rows->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->info('No API consumers are registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Owner', 'Status', 'Version', 'Scopes', 'Organization access', 'Last use', 'Next token expiry', 'Findings'],
            $rows->map(fn (array $row): array => [
                $row['code'], $row['owner_institution'] ?? '—', $row['status'], $row['api_version'], count($row['scopes']),
                $row['organization_access'].' ('.$row['organization_grants'].')', $row['last_used_at'] ?? 'never',
                $row['next_token_expiry'] ?? '—', implode(', ', $row['findings']) ?: '—',
            ])->all(),
        );
        $this->line($rows->count().' consumer(s) in '.app()->environment().'; '.$rows->filter(fn (array $row): bool => $row['findings'] !== [])->count().' need review.');

        return self::SUCCESS;
    }
}
