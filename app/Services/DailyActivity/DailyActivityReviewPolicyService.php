<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Enums\DailyActivityReviewMode;
use App\Enums\DailyActivityStatus;
use App\Models\DailyActivityLog;
use App\Models\DailyActivityReviewPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which submitted days need a reviewer, per organization.
 *
 * An organization without a policy row, or with "inherit", follows the
 * city-wide Manager Review Required setting. The policy decides what waits
 * in review queues and what EPMS counts as final; it never changes a
 * record's status, and a reviewer may still review any day they cover.
 */
class DailyActivityReviewPolicyService
{
    public function __construct(private readonly DailyActivitySettings $settings) {}

    /** The effective mode: never Inherit. */
    public function modeFor(?string $organizationId): DailyActivityReviewMode
    {
        return $this->explicitModes()[$organizationId ?? ''] ?? $this->cityDefault();
    }

    public function cityDefault(): DailyActivityReviewMode
    {
        return $this->settings->managerReviewRequired() ? DailyActivityReviewMode::All : DailyActivityReviewMode::None;
    }

    /** Does this day wait for a reviewer under its organization's policy? */
    public function requiresReview(DailyActivityLog $log): bool
    {
        return match ($this->modeFor($log->organization_id)) {
            DailyActivityReviewMode::All => true,
            DailyActivityReviewMode::None => false,
            default => (bool) $log->is_late || $log->status === DailyActivityStatus::Resubmitted,
        };
    }

    /** Could anything need review anywhere? (Hides review screens when nothing can.) */
    public function anyReviewRequired(): bool
    {
        if ($this->cityDefault() === DailyActivityReviewMode::All) {
            return true;
        }

        foreach ($this->explicitModes() as $mode) {
            if ($mode !== DailyActivityReviewMode::None) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only the logs whose organization's policy requires review, in SQL.
     *
     * @param  Builder<DailyActivityLog>  $query
     * @return Builder<DailyActivityLog>
     */
    public function constrainToReviewRequired(Builder $query): Builder
    {
        $byMode = [];
        foreach ($this->explicitModes() as $organizationId => $mode) {
            $byMode[$mode->value][] = $organizationId;
        }
        $all = $byMode[DailyActivityReviewMode::All->value] ?? [];
        $none = $byMode[DailyActivityReviewMode::None->value] ?? [];
        $lateOnly = $byMode[DailyActivityReviewMode::LateOnly->value] ?? [];
        $cityReviews = $this->cityDefault() === DailyActivityReviewMode::All;

        return $query->where(function (Builder $where) use ($all, $none, $lateOnly, $cityReviews): void {
            if ($cityReviews) {
                // Everything, except organizations that opted out or review only late days.
                $where->whereNotIn('organization_id', [...$none, ...$lateOnly]);
            } else {
                $where->whereIn('organization_id', $all);
            }

            if ($lateOnly !== []) {
                $where->orWhere(fn (Builder $late) => $late
                    ->whereIn('organization_id', $lateOnly)
                    ->where(fn (Builder $which) => $which
                        ->where('is_late', true)
                        ->orWhere('status', DailyActivityStatus::Resubmitted->value)));
            }
        });
    }

    /**
     * Restrict a log query to days whose quantities are final for EPMS
     * measurement in this organization: approval where review is required,
     * submission where it is not. Under "late only", an on-time first
     * submission is final; late and resubmitted days need approval.
     *
     * @param  Builder<DailyActivityLog>  $query
     * @return Builder<DailyActivityLog>
     */
    public function constrainToFinal(Builder $query, ?string $organizationId): Builder
    {
        return match ($this->modeFor($organizationId)) {
            DailyActivityReviewMode::All => $query->where('status', DailyActivityStatus::Approved->value),
            DailyActivityReviewMode::None => $query->whereIn('status', DailyActivityStatus::submittedValues()),
            default => $query->where(fn (Builder $final) => $final
                ->where('status', DailyActivityStatus::Approved->value)
                ->orWhere(fn (Builder $onTime) => $onTime
                    ->whereIn('status', [DailyActivityStatus::Submitted->value, DailyActivityStatus::UnderReview->value])
                    ->where('is_late', false))),
        };
    }

    /** @return array<string, DailyActivityReviewMode> */
    private function explicitModes(): array
    {
        // One row per organization at most, and only those that changed the
        // default: a small table. Deliberately not memoized on this object,
        // which can outlive a request (controllers are cached on routes, and
        // long-running workers reuse them), so a policy change applies at once.
        return DailyActivityReviewPolicy::query()
            ->where('review_mode', '!=', DailyActivityReviewMode::Inherit->value)
            ->get(['organization_id', 'review_mode'])
            ->mapWithKeys(fn (DailyActivityReviewPolicy $policy): array => [$policy->organization_id => $policy->review_mode])
            ->all();
    }
}
