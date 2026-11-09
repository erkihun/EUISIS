<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Application-side spend control for paid external services.
 *
 * Rate limiters stop one caller; this stops the TOTAL. Every paid call goes
 *
 *   reserve()  -> budget + per-recipient checks under a lock, then a
 *                 "reserved" usage row, so parallel requests cannot all pass
 *                 the same check and overshoot the cap
 *   settle()   -> the row becomes "sent" or "failed"
 *
 * A failed call still counts toward the cap: a provider that bills attempts
 * would otherwise let a failing loop spend without limit.
 *
 * This complements, and does not replace, a hard spending limit configured
 * at the provider account (see the security boundary report).
 */
class ExternalUsageBudgetService
{
    private const LOCK_SECONDS = 10;

    /**
     * @param  array{provider?: string, purpose?: string, user_id?: int|null, recipient?: string|null}  $context
     * @return array{allowed: bool, usage_id: ?string, reason: ?string}
     */
    public function reserve(string $service, array $context = []): array
    {
        $limits = (array) config("security.external_usage.{$service}", []);
        $recipientHash = isset($context['recipient']) && $context['recipient'] !== ''
            ? hash('sha256', (string) $context['recipient'])
            : null;

        $lock = Cache::lock("external-usage-budget:{$service}", self::LOCK_SECONDS);

        return $lock->block(self::LOCK_SECONDS, function () use ($service, $context, $limits, $recipientHash): array {
            $reason = $this->refusalReason($service, $limits, $recipientHash);

            $id = (string) Str::uuid7();
            DB::table('external_service_usages')->insert([
                'id' => $id,
                'provider' => (string) ($context['provider'] ?? 'unknown'),
                'service' => $service,
                'purpose' => $context['purpose'] ?? null,
                'user_id' => $context['user_id'] ?? null,
                'recipient_hash' => $recipientHash,
                'units' => 1,
                'status' => $reason === null ? 'reserved' : 'refused',
                'refusal_reason' => $reason,
                'occurred_at' => now(),
            ]);

            if ($reason !== null) {
                Log::warning('External service call refused by budget.', ['service' => $service, 'reason' => $reason]);

                return ['allowed' => false, 'usage_id' => null, 'reason' => $reason];
            }

            $this->warnNearCap($service, $limits);

            return ['allowed' => true, 'usage_id' => $id, 'reason' => null];
        });
    }

    public function settle(?string $usageId, bool $succeeded): void
    {
        if ($usageId === null) {
            return;
        }

        DB::table('external_service_usages')
            ->where('id', $usageId)
            ->update(['status' => $succeeded ? 'sent' : 'failed']);
    }

    /** Whether the service has been switched off entirely (a kill switch). */
    public function isEnabled(string $service): bool
    {
        return (bool) config("security.external_usage.{$service}.enabled", true);
    }

    /** @param array<string, mixed> $limits */
    private function refusalReason(string $service, array $limits, ?string $recipientHash): ?string
    {
        if (! (bool) ($limits['enabled'] ?? true)) {
            return 'disabled';
        }

        $counted = fn () => DB::table('external_service_usages')
            ->where('service', $service)
            ->whereIn('status', ['reserved', 'sent', 'failed']);

        $dailyCap = (int) ($limits['daily_cap'] ?? 0);
        if ($dailyCap > 0 && $counted()->where('occurred_at', '>=', now()->startOfDay())->count() >= $dailyCap) {
            return 'daily_cap';
        }

        $monthlyCap = (int) ($limits['monthly_cap'] ?? 0);
        if ($monthlyCap > 0 && $counted()->where('occurred_at', '>=', now()->startOfMonth())->count() >= $monthlyCap) {
            return 'monthly_cap';
        }

        $recipientCap = (int) ($limits['per_recipient_daily_cap'] ?? 0);
        if ($recipientHash !== null && $recipientCap > 0
            && $counted()->where('recipient_hash', $recipientHash)->where('occurred_at', '>=', now()->subDay())->count() >= $recipientCap) {
            return 'recipient_cap';
        }

        return null;
    }

    /** @param array<string, mixed> $limits */
    private function warnNearCap(string $service, array $limits): void
    {
        $dailyCap = (int) ($limits['daily_cap'] ?? 0);
        if ($dailyCap <= 0) {
            return;
        }

        $used = DB::table('external_service_usages')
            ->where('service', $service)
            ->whereIn('status', ['reserved', 'sent', 'failed'])
            ->where('occurred_at', '>=', now()->startOfDay())
            ->count();

        // Once, at the moment the warning threshold is crossed.
        if ($used === (int) ceil($dailyCap * 0.8)) {
            Log::warning('External service usage reached 80% of the daily cap.', ['service' => $service, 'used' => $used, 'cap' => $dailyCap]);
        }
    }
}
