<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Questionnaire answers summarised over a set of submissions (ADR 0017), for
 * the Situation Room's public voice panels. Aggregates only: no answer text
 * and no identity leaves the module.
 */
interface FeedbackAnswerStatistics
{
    /**
     * @param  Builder  $submissions  a query over `employee_service_feedback as f`, already scoped
     * @return list<array{code: string, answered: int, average: float|null, distribution: array<int, int>}>
     */
    public function ratingSummaryOf(Builder $submissions): array;

    /**
     * @param  Builder  $submissions  a query over `employee_service_feedback as f`, already scoped
     * @return list<array{code: string, answered: int, choices: array<string, int>}>
     */
    public function choiceSummaryOf(Builder $submissions): array;

    /**
     * The current wording of the given question codes and their options.
     *
     * @param  list<string>  $codes
     * @return array<string, array{en: string|null, am: string|null, sort: int, options: array<string, array{en: string|null, am: string|null, sort: int}>}>
     */
    public function labels(array $codes): array;
}
