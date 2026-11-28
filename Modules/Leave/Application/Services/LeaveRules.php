<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use Modules\Leave\Domain\Models\LeaveRule;
use Modules\Leave\Domain\Models\LeaveRuleSet;
use Modules\Leave\Domain\Models\LeaveType;

/**
 * Which legal figures apply on a day (ADR 0039, decision 3). The law is
 * data: the newest active rule set whose period contains the date wins.
 * Answers are remembered for the request so a page does not ask twice.
 */
class LeaveRules
{
    /** @var array<string, ?LeaveRuleSet> */
    private array $sets = [];

    public function ruleSetOn(string $date): ?LeaveRuleSet
    {
        if (! array_key_exists($date, $this->sets)) {
            $this->sets[$date] = LeaveRuleSet::query()->inForceOn($date)->with('rules')->orderByDesc('effective_from')->orderByDesc('created_at')->first();
        }

        return $this->sets[$date];
    }

    public function ruleFor(LeaveType $type, string $date): ?LeaveRule
    {
        return $this->ruleSetOn($date)?->rules->firstWhere('leave_type_id', $type->id);
    }

    public function forget(): void
    {
        $this->sets = [];
    }

    /**
     * The last budget year after which unused days of $entitlementYear are
     * closed. Under "authorized" carry-over the days wait, unusable, for the
     * head of the institution's decision until the carry-over years run out.
     */
    public function lastChanceYear(?LeaveRule $rule, int $entitlementYear, ?int $postponedUntil): int
    {
        if ($rule?->carry_over_mode === LeaveRule::CARRY_AUTHORIZED) {
            return $entitlementYear + max(0, (int) $rule->carry_over_years);
        }

        return $this->usableUntil($rule, $entitlementYear, $postponedUntil);
    }

    /** The last budget year in which the days of $entitlementYear may be used. */
    public function usableUntil(?LeaveRule $rule, int $entitlementYear, ?int $postponedUntil): int
    {
        return match ($rule?->carry_over_mode) {
            LeaveRule::CARRY_AUTOMATIC => $entitlementYear + max(0, (int) $rule->carry_over_years),
            LeaveRule::CARRY_AUTHORIZED => max($entitlementYear, (int) ($postponedUntil ?? $entitlementYear)),
            default => $entitlementYear,
        };
    }
}
