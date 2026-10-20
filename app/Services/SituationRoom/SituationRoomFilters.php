<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Support\Database\SqlDateBucket;
use Illuminate\Support\Carbon;

/**
 * The executive filter bar, validated: period (with its previous equivalent
 * period), submission type, target type, category and status. Dates are
 * Gregorian on the server; the page shows them in the visitor's calendar.
 */
final readonly class SituationRoomFilters
{
    private function __construct(
        public string $period,
        public Carbon $start,
        public Carbon $end,
        public Carbon $previousStart,
        public Carbon $previousEnd,
        public ?string $type,
        public ?string $target,
        public ?string $categoryId,
        public ?string $status,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromInput(array $input, ?Carbon $now = null): self
    {
        $now ??= now();
        $period = in_array($input['period'] ?? null, SituationRoomMetrics::PERIODS, true) ? (string) $input['period'] : SituationRoomMetrics::DEFAULT_PERIOD;

        [$start, $end] = self::range($period, $input, $now);
        if ($period === 'custom' && ($start === null || $end === null)) {
            $period = SituationRoomMetrics::DEFAULT_PERIOD;
            [$start, $end] = self::range($period, [], $now);
        }

        // The previous equivalent period: the calendar unit before, or the same length before.
        [$previousStart, $previousEnd] = match ($period) {
            'month' => [$start->copy()->subMonthNoOverflow()->startOfMonth(), $start->copy()],
            'quarter' => [$start->copy()->subQuarterNoOverflow()->startOfQuarter(), $start->copy()],
            'year' => [$start->copy()->subYear()->startOfYear(), $start->copy()],
            default => [$start->copy()->subSeconds((int) $start->diffInSeconds($end)), $start->copy()],
        };

        $pick = static fn (string $key, array $allowed): ?string => in_array($input[$key] ?? null, $allowed, true) ? (string) $input[$key] : null;
        $category = $input['category_id'] ?? null;

        return new self(
            period: $period,
            start: $start,
            end: $end,
            previousStart: $previousStart,
            previousEnd: $previousEnd,
            type: $pick('type', SituationRoomMetrics::TYPES),
            target: $pick('target', SituationRoomMetrics::TARGETS),
            categoryId: is_string($category) && ($category === 'none' || preg_match('/^[0-9a-f-]{36}$/i', $category) === 1) ? $category : null,
            status: $pick('status', array_keys(SituationRoomMetrics::STATUS_BUCKETS)),
        );
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} start inclusive, end exclusive */
    private static function range(string $period, array $input, Carbon $now): array
    {
        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->addDay()->startOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->addDay()->startOfDay()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->addDay()->startOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->addDay()->startOfDay()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->addDay()->startOfDay()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->addDay()->startOfDay()],
            'custom' => self::customRange($input),
        };
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private static function customRange(array $input): array
    {
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;
        if (! is_string($from) || ! is_string($to) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            return [null, null];
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->addDay()->startOfDay();

        // At most ten years, and never backwards.
        return $start < $end && $start->diffInDays($end) <= 3660 ? [$start, $end] : [null, null];
    }

    public function bucketUnit(): string
    {
        return SqlDateBucket::unitForDays((int) ceil($this->start->diffInDays($this->end)));
    }

    /** Stable key for caches and links. @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'period' => $this->period,
            'from' => $this->start->toDateString(),
            'to' => $this->end->copy()->subDay()->toDateString(),
            'previous_from' => $this->previousStart->toDateString(),
            'previous_to' => $this->previousEnd->copy()->subDay()->toDateString(),
            'type' => $this->type,
            'target' => $this->target,
            'category_id' => $this->categoryId,
            'status' => $this->status,
            'unit' => $this->bucketUnit(),
        ], fn ($value): bool => $value !== null);
    }
}
