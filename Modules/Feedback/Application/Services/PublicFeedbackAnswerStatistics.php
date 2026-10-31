<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Feedback\Contracts\FeedbackAnswerStatistics;
use Modules\Feedback\Domain\Enums\PublicFeedbackQuestionSetStatus;

/**
 * Aggregates of the structured questionnaire answers, for the Executive
 * Situation Room and reports (docs/organization-feedback-analytics.md).
 *
 * Answers are grouped by the question's stable code, so one question is
 * followed across questionnaire versions. Everything is counted from typed
 * columns — never parsed out of free text, and free-text answers are never
 * aggregated. These are what visitors said about a service or an
 * institution; they are not a measure of any individual employee and must
 * not be used as one.
 *
 * Two entry points:
 * - the `*Of()` methods take a query over `employee_service_feedback as f`
 *   that the caller has already scoped (the Situation Room passes its
 *   hierarchy-scoped, filtered, in-period base), so the answers inherit
 *   exactly the submissions the viewer may count;
 * - `ratingSummary()` / `choiceCounts()` build that query from simple
 *   filters, for reports.
 *
 * Hidden submissions are left out, as everywhere else in public-voice analytics.
 */
final class PublicFeedbackAnswerStatistics implements FeedbackAnswerStatistics
{
    /**
     * Per rating question: how many answered, the average (1–5) and the
     * distribution over the five points.
     *
     * @param  array{organization_target_id?: string|null, service_id?: string|null, type?: string|null, from?: CarbonInterface|null, to?: CarbonInterface|null}  $filters
     * @return list<array{code: string, answered: int, average: float|null, distribution: array<int, int>}>
     */
    public function ratingSummary(array $filters = []): array
    {
        return $this->ratingSummaryOf($this->submissions($filters));
    }

    /**
     * @param  Builder  $submissions  a query over `employee_service_feedback as f`, already scoped
     * @return list<array{code: string, answered: int, average: float|null, distribution: array<int, int>}>
     */
    public function ratingSummaryOf(Builder $submissions): array
    {
        $rows = $this->answersOf($submissions)
            ->where('a.question_type', 'rating')
            ->whereNotNull('a.rating_value')
            ->selectRaw('a.question_code AS code, a.rating_value AS rating, COUNT(*) AS total')
            ->groupBy('a.question_code', 'a.rating_value')
            ->get();

        $summary = [];

        foreach ($rows as $row) {
            $code = (string) $row->code;
            $summary[$code] ??= ['code' => $code, 'answered' => 0, 'average' => null, 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0], 'sum' => 0];
            $summary[$code]['distribution'][(int) $row->rating] = (int) $row->total;
            $summary[$code]['answered'] += (int) $row->total;
            $summary[$code]['sum'] += (int) $row->rating * (int) $row->total;
        }

        ksort($summary);

        return array_values(array_map(static function (array $item): array {
            $item['average'] = $item['answered'] > 0 ? round($item['sum'] / $item['answered'], 2) : null;
            unset($item['sum']);

            return $item;
        }, $summary));
    }

    /**
     * Per choice of one single- or multiple-choice question (or yes/no): how many chose it.
     *
     * @param  array{organization_target_id?: string|null, service_id?: string|null, type?: string|null, from?: CarbonInterface|null, to?: CarbonInterface|null}  $filters
     * @return array<string, int>
     */
    public function choiceCounts(string $questionCode, array $filters = []): array
    {
        $counts = [];

        foreach ($this->choiceSummaryOf($this->submissions($filters)) as $question) {
            if ($question['code'] === $questionCode) {
                foreach ($question['choices'] as $choice => $total) {
                    $counts[$choice] = $total;
                }
            }
        }

        return $counts;
    }

    /**
     * Every choice question (single, multiple, yes/no) answered in the slice:
     * how many submissions answered it, and how many chose each choice. On a
     * multiple-choice question the choices add up to more than `answered`.
     *
     * @param  Builder  $submissions  a query over `employee_service_feedback as f`, already scoped
     * @return list<array{code: string, answered: int, choices: array<string, int>}>
     */
    public function choiceSummaryOf(Builder $submissions): array
    {
        $answers = $this->answersOf($submissions)->whereIn('a.question_type', ['single_choice', 'multiple_choice', 'yes_no']);

        $answered = (clone $answers)
            ->selectRaw('a.question_code AS code, COUNT(DISTINCT a.submission_id) AS total')
            ->groupBy('a.question_code')
            ->pluck('total', 'code');

        $yesNo = (clone $answers)->whereNotNull('a.bool_value')
            ->selectRaw('a.question_code AS code, a.bool_value AS bucket, COUNT(*) AS total')
            ->groupBy('a.question_code', 'a.bool_value')
            ->get();

        $options = (clone $answers)->whereNotNull('a.option_id')
            ->join('public_feedback_question_options as o', 'o.id', '=', 'a.option_id')
            ->selectRaw('a.question_code AS code, o.code AS bucket, COUNT(*) AS total')
            ->groupBy('a.question_code', 'o.code')
            ->get();

        $summary = [];

        foreach ($answered as $code => $total) {
            $summary[(string) $code] = ['code' => (string) $code, 'answered' => (int) $total, 'choices' => []];
        }

        foreach ($yesNo as $row) {
            // PostgreSQL returns a boolean, SQLite 0/1.
            $bucket = filter_var($row->bucket, FILTER_VALIDATE_BOOL) ? 'yes' : 'no';
            $summary[(string) $row->code]['choices'][$bucket] = (int) $row->total;
        }

        foreach ($options as $row) {
            $summary[(string) $row->code]['choices'][(string) $row->bucket] = (int) $row->total;
        }

        ksort($summary);

        return array_values($summary);
    }

    /**
     * The wording to show for each question code: that of the newest
     * published or retired version asking it, and its order there. Drafts are
     * never read. A submission's own detail screen keeps the wording of the
     * version it answered (PublicFeedbackQuestionnaire::presentAnswers).
     *
     * @param  list<string>  $codes
     * @return array<string, array{en: string|null, am: string|null, sort: int, options: array<string, array{en: string|null, am: string|null, sort: int}>}>
     */
    public function labels(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $questions = DB::table('public_feedback_questions as q')
            ->join('public_feedback_question_sets as s', 's.id', '=', 'q.question_set_id')
            ->where('s.code', PublicFeedbackQuestionnaire::ORGANIZATION_FORM)
            ->whereIn('s.status', [PublicFeedbackQuestionSetStatus::Published->value, PublicFeedbackQuestionSetStatus::Retired->value])
            ->whereIn('q.code', $codes)
            ->orderByDesc('s.version_no')
            ->get(['q.id', 'q.code', 'q.label_en', 'q.label_am', 'q.sort_order']);

        $latest = [];
        foreach ($questions as $question) {
            $latest[(string) $question->code] ??= $question;
        }

        $options = DB::table('public_feedback_question_options')
            ->whereIn('question_id', array_map(static fn (object $question): string => (string) $question->id, array_values($latest)))
            ->get(['question_id', 'code', 'label_en', 'label_am', 'sort_order'])
            ->groupBy('question_id');

        $labels = [];
        foreach ($latest as $code => $question) {
            $labels[$code] = [
                'en' => $question->label_en,
                'am' => $question->label_am,
                'sort' => (int) $question->sort_order,
                'options' => ($options->get($question->id) ?? collect())
                    ->mapWithKeys(static fn (object $option): array => [(string) $option->code => ['en' => $option->label_en, 'am' => $option->label_am, 'sort' => (int) $option->sort_order]])
                    ->all(),
            ];
        }

        return $labels;
    }

    /** The answers of the given submissions. */
    private function answersOf(Builder $submissions): Builder
    {
        return (clone $submissions)->join('public_feedback_answers as a', 'a.submission_id', '=', 'f.id');
    }

    /** @param  array<string, mixed>  $filters */
    private function submissions(array $filters): Builder
    {
        $query = DB::table('employee_service_feedback as f')->where('f.status', '!=', 'hidden');

        if (! empty($filters['organization_target_id'])) {
            $query->where('f.organization_target_id', $filters['organization_target_id']);
        }

        if (! empty($filters['service_id'])) {
            $query->where('f.service_id', $filters['service_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('f.type', $filters['type']);
        }

        if (($filters['from'] ?? null) instanceof CarbonInterface) {
            $query->where('f.submitted_at', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) instanceof CarbonInterface) {
            $query->where('f.submitted_at', '<=', $filters['to']);
        }

        return $query;
    }
}
