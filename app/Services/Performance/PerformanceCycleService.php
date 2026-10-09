<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\CycleStatus;
use App\Models\EmployeePerformanceAgreement;
use App\Models\PerformanceCycle;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Performance cycle lifecycle (docs/epms-workflow.md §1).
 *
 * One non-cancelled cycle per organization scope per period (a city-wide
 * cycle has no organization). A CLOSED or CANCELLED cycle is read-only.
 */
final class PerformanceCycleService
{
    /** @var array<string, list<CycleStatus>> */
    private const TRANSITIONS = [
        'DRAFT' => [CycleStatus::Planning, CycleStatus::Cancelled],
        'PLANNING' => [CycleStatus::Cascaded, CycleStatus::Cancelled],
        'CASCADED' => [CycleStatus::Agreement, CycleStatus::Cancelled],
        'AGREEMENT' => [CycleStatus::Active, CycleStatus::Cancelled],
        'ACTIVE' => [CycleStatus::MidYearReview, CycleStatus::YearEndReview],
        'MID_YEAR_REVIEW' => [CycleStatus::Active, CycleStatus::YearEndReview],
        'YEAR_END_REVIEW' => [CycleStatus::Calibration, CycleStatus::Finalized],
        'CALIBRATION' => [CycleStatus::Finalized],
        'FINALIZED' => [CycleStatus::Closed],
        'CLOSED' => [],
        'CANCELLED' => [],
    ];

    public function __construct(private readonly EpmsAccess $access, private readonly EpmsAudit $audit) {}

    /** @return list<string> the statuses a cycle may move to next */
    public static function nextStatuses(PerformanceCycle $cycle): array
    {
        return array_map(fn (CycleStatus $s) => $s->value, self::TRANSITIONS[$cycle->status->value]);
    }

    public static function isReadOnly(PerformanceCycle $cycle): bool
    {
        return in_array($cycle->status, [CycleStatus::Closed, CycleStatus::Cancelled], true);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): PerformanceCycle
    {
        $organizationId = $data['organization_id'] ?? null;
        $this->access->authorize($organizationId === null
            ? $actor->can('performance_cycles.create') && $actor->hasAnyRole(['Super Admin', 'City Admin', 'System Admin'])
            : $this->access->inScope($actor, 'performance_cycles.create', $organizationId));

        $this->assertDates($data);
        $this->assertNoOverlap($organizationId, $data['start_date'], $data['end_date']);

        $cycle = new PerformanceCycle($data);
        $cycle->forceFill(['status' => CycleStatus::Draft, 'created_by' => $actor->getKey()])->save();

        $this->audit->record(AuditEventType::PerformanceCycleCreated, $actor, $cycle, $cycle->only(['code', 'start_date', 'end_date', 'organization_id']));

        return $cycle;
    }

    /** Fields that define a cycle's period: fixed once plans are being cascaded into it. */
    private const PERIOD_FIELDS = ['code', 'start_date', 'end_date', 'planning_start_date', 'planning_end_date'];

    /** Whether the period (code, dates, planning window) may still change. */
    public static function periodEditable(PerformanceCycle $cycle): bool
    {
        return in_array($cycle->status, [CycleStatus::Draft, CycleStatus::Planning], true);
    }

    /**
     * Correct a cycle. Names and review windows can change until the cycle is
     * closed; the code, period and planning window only while it is a draft or
     * in planning, because plans, targets and agreements are dated inside it.
     * The organization never changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PerformanceCycle $cycle, array $data, User $actor): PerformanceCycle
    {
        $this->access->authorize($cycle->organization_id === null
            ? $actor->can('performance_cycles.update') && $actor->hasAnyRole(['Super Admin', 'City Admin', 'System Admin'])
            : $this->access->inScope($actor, 'performance_cycles.update', $cycle->organization_id));

        return DB::transaction(function () use ($cycle, $data, $actor): PerformanceCycle {
            /** @var PerformanceCycle $locked */
            $locked = PerformanceCycle::query()->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();
            if (self::isReadOnly($locked)) {
                throw ValidationException::withMessages(['status' => __('performance.errors.cycle_read_only')]);
            }

            unset($data['organization_id']);
            $current = self::dates($locked);
            if (! self::periodEditable($locked)) {
                foreach (self::PERIOD_FIELDS as $field) {
                    $stored = $field === 'code' ? $locked->code : $current[$field];
                    if (array_key_exists($field, $data) && ($data[$field] ?: null) !== $stored) {
                        throw ValidationException::withMessages([$field => __('performance.errors.cycle_period_locked')]);
                    }
                }
            }

            $merged = [...$current, ...array_intersect_key($data, $current)];
            $this->assertDates($merged);
            if ($merged['start_date'] !== $current['start_date'] || $merged['end_date'] !== $current['end_date']) {
                $this->assertNoOverlap($locked->organization_id, $merged['start_date'], $merged['end_date'], $locked->getKey());
            }

            $old = ['code' => $locked->code, 'name_en' => $locked->name_en, 'name_am' => $locked->name_am, ...$current];
            $locked->fill(array_intersect_key($data, array_flip(['code', 'name_en', 'name_am', ...array_keys($current)])))->save();
            $new = ['code' => $locked->code, 'name_en' => $locked->name_en, 'name_am' => $locked->name_am, ...self::dates($locked)];
            $this->audit->record(AuditEventType::PerformanceCycleUpdated, $actor, $locked, array_diff_assoc($new, $old), array_intersect_key($old, array_diff_assoc($new, $old)));

            return $locked;
        });
    }

    /** @return array<string, ?string> the cycle's dates as Y-m-d */
    private static function dates(PerformanceCycle $cycle): array
    {
        $fields = ['start_date', 'end_date', 'planning_start_date', 'planning_end_date', 'midyear_review_start_date', 'midyear_review_end_date', 'yearend_review_start_date', 'yearend_review_end_date'];

        return array_combine($fields, array_map(fn (string $field): ?string => $cycle->getAttribute($field)?->toDateString(), $fields));
    }

    public function transition(PerformanceCycle $cycle, CycleStatus $to, User $actor): PerformanceCycle
    {
        $permission = match ($to) {
            CycleStatus::Active, CycleStatus::MidYearReview, CycleStatus::YearEndReview, CycleStatus::Calibration => 'performance_cycles.activate',
            CycleStatus::Finalized, CycleStatus::Closed, CycleStatus::Cancelled => 'performance_cycles.close',
            default => 'performance_cycles.update',
        };
        $this->access->authorize($cycle->organization_id === null
            ? $actor->can($permission) && $actor->hasAnyRole(['Super Admin', 'City Admin', 'System Admin'])
            : $this->access->inScope($actor, $permission, $cycle->organization_id));

        return DB::transaction(function () use ($cycle, $to, $actor): PerformanceCycle {
            /** @var PerformanceCycle $locked */
            $locked = PerformanceCycle::query()->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! in_array($to, self::TRANSITIONS[$from->value], true)) {
                throw ValidationException::withMessages(['status' => __('performance.errors.invalid_transition', ['from' => $from->value, 'to' => $to->value])]);
            }

            $attributes = ['status' => $to];
            if ($to === CycleStatus::Active && $from === CycleStatus::Agreement) {
                // Becomes the current cycle for its scope; the previous one stops being current.
                $key = $locked->organization_id ?? 'global';
                PerformanceCycle::query()->where('current_key', $key)->whereKeyNot($locked->getKey())
                    ->update(['current_key' => null, 'is_current' => false]);
                $attributes += ['is_current' => true, 'current_key' => $key];

                // Agreements approved during the agreement phase start now.
                EmployeePerformanceAgreement::query()->where('cycle_id', $locked->getKey())
                    ->where('status', AgreementStatus::Agreed->value)->update(['status' => AgreementStatus::Active->value]);
            }
            if (in_array($to, [CycleStatus::Closed, CycleStatus::Cancelled], true)) {
                $attributes += ['is_current' => false, 'current_key' => null];
            }

            $locked->forceFill($attributes)->save();
            $this->audit->record(AuditEventType::PerformanceCycleStatusChanged, $actor, $locked, ['status' => $to->value], ['status' => $from->value]);

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    private function assertDates(array $data): void
    {
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => __('performance.errors.end_before_start')]);
        }

        foreach (['planning', 'midyear_review', 'yearend_review'] as $window) {
            $from = $data["{$window}_start_date"] ?? null;
            $to = $data["{$window}_end_date"] ?? null;
            if (($from && ! $to) || (! $from && $to)) {
                throw ValidationException::withMessages(["{$window}_end_date" => __('performance.errors.window_incomplete')]);
            }
            if ($from && $to && Carbon::parse($to)->lt(Carbon::parse($from))) {
                throw ValidationException::withMessages(["{$window}_end_date" => __('performance.errors.end_before_start')]);
            }
            // Reviews happen inside the cycle; planning may start before it.
            if ($window !== 'planning' && $from && (Carbon::parse($from)->lt($start) || Carbon::parse($to)->gt($end->copy()->addDays(60)))) {
                throw ValidationException::withMessages(["{$window}_start_date" => __('performance.errors.window_outside_cycle')]);
            }
        }
    }

    private function assertNoOverlap(?string $organizationId, string $start, string $end, ?string $exceptId = null): void
    {
        $overlap = PerformanceCycle::query()
            ->when($organizationId === null, fn ($q) => $q->whereNull('organization_id'), fn ($q) => $q->where('organization_id', $organizationId))
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereNotIn('status', [CycleStatus::Cancelled->value])
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => __('performance.errors.cycle_overlap')]);
        }
    }
}
