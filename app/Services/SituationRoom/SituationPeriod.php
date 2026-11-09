<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use Carbon\CarbonImmutable;

/**
 * The time window of a Situation Room view, in the application timezone
 * (the localization setting applied at boot).
 *
 * Bounds are inclusive whole days: `from` is 00:00:00.000000 of the first day
 * and `to` 23:59:59.999999 of the last, so a "month" never loses its last
 * evening or gains the next morning. The previous period has the same length
 * and ends the moment before this one starts, so comparisons are like for
 * like.
 */
final readonly class SituationPeriod
{
    public const PRESETS = ['today', 'week', 'month', 'quarter', 'year', 'custom'];

    public function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function fromInput(?string $preset, ?string $from = null, ?string $to = null, string $default = 'month'): self
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $preset = in_array($preset, self::PRESETS, true) ? $preset : (in_array($default, self::PRESETS, true) ? $default : 'month');

        if ($preset === 'custom') {
            $start = self::date($from, $timezone);
            $end = self::date($to, $timezone);

            if ($start === null || $end === null || $end->lt($start) || $start->diffInDays($end) > 3660) {
                // An incomplete or impossible custom range falls back to this month, never to "everything".
                $preset = 'month';
            } else {
                return new self('custom', $start->startOfDay(), $end->endOfDay());
            }
        }

        [$start, $end] = match ($preset) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'week' => [$now->startOfWeek(), $now->endOfDay()],
            'quarter' => [$now->firstOfQuarter()->startOfDay(), $now->endOfDay()],
            'year' => [$now->startOfYear(), $now->endOfDay()],
            default => [$now->startOfMonth(), $now->endOfDay()],
        };

        return new self($preset, $start, $end);
    }

    /** The equally long window immediately before this one. */
    public function previous(): self
    {
        $length = $this->from->diffInSeconds($this->to) + 1;
        $to = $this->from->subMicrosecond();

        return new self($this->preset, $to->subSeconds((int) $length - 1)->startOfDay(), $to);
    }

    /** Bucket for a trend chart: days up to two months, weeks up to a year, then months. */
    public function granularity(): string
    {
        $days = $this->from->diffInDays($this->to);

        return $days <= 62 ? 'day' : ($days <= 400 ? 'week' : 'month');
    }

    /** @return array{preset: string, from: string, to: string, granularity: string, timezone: string} */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'granularity' => $this->granularity(),
            'timezone' => $this->from->getTimezone()->getName(),
        ];
    }

    private static function date(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
