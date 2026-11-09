<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts\Data;

use InvalidArgumentException;

/**
 * The star ratings clients gave one employee through public feedback: how
 * many, their mean, and how many of each star. Hidden (moderated) entries
 * are never counted, so a suppressed abusive rating cannot move the score.
 */
final readonly class EmployeeFeedbackRatingSummary
{
    public const MIN_STARS = 1;

    public const MAX_STARS = 5;

    /** @var array<int, int> star (1–5) => count, every star present */
    public array $distribution;

    public int $total;

    /** Mean rating rounded to two places; null when no rating exists. */
    public ?float $average;

    /** @param  array<int, int>  $counts  star => count; missing stars count as zero */
    public function __construct(array $counts)
    {
        $distribution = [];
        foreach (range(self::MIN_STARS, self::MAX_STARS) as $star) {
            $count = (int) ($counts[$star] ?? 0);
            if ($count < 0) {
                throw new InvalidArgumentException('A rating count cannot be negative.');
            }
            $distribution[$star] = $count;
        }

        $unknown = array_diff(array_keys($counts), array_keys($distribution));
        if ($unknown !== []) {
            throw new InvalidArgumentException('A rating is a whole number of stars from 1 to 5.');
        }

        $total = array_sum($distribution);
        $weighted = 0;
        foreach ($distribution as $star => $count) {
            $weighted += $star * $count;
        }

        $this->distribution = $distribution;
        $this->total = $total;
        $this->average = $total === 0 ? null : round($weighted / $total, 2);
    }

    /** @return array{total: int, average: ?float, distribution: list<array{stars: int, count: int}>} */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'average' => $this->average,
            // Five stars first, the way a rating breakdown is read.
            'distribution' => array_map(
                fn (int $star): array => ['stars' => $star, 'count' => $this->distribution[$star]],
                range(self::MAX_STARS, self::MIN_STARS),
            ),
        ];
    }
}
