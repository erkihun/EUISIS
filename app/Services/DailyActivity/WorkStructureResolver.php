<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Enums\TaskQualitySource;
use App\Models\DailyActivityItem;
use App\Models\DailyActivityLog;
use App\Models\PositionService;
use App\Models\PositionServiceSubService;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Turns the employee's choice of main task into a measured item.
 *
 * Only the task (and, for checking, the sub-service) comes from the browser.
 * Everything else is resolved here from master data: the sub-service and
 * position service the task belongs to, the standard in force on the work
 * date, the planned values copied from it, the time taken and the scores.
 * Values a browser sends for any of those are never used.
 *
 * The standard is resolved for the WORK DATE, not today, and an item already
 * measured on this log keeps the standard it was measured against, so a
 * newer version never rescores recorded work.
 */
class WorkStructureResolver
{
    public function __construct(
        private readonly DailyWorkPerformanceCalculator $calculator,
        private readonly DailyActivitySettings $settings,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items  normalized items
     * @return array<int, array<string, mixed>> items with the resolved structure and scores
     */
    public function resolve(array $items, ?string $positionId, ?string $organizationId, Carbon $date, ?DailyActivityLog $existing): array
    {
        $taskIds = array_values(array_unique(array_filter(array_column($items, 'task_id'))));
        $previous = $existing?->items()->get()->keyBy('id') ?? collect();
        $retainedTaskIds = $previous->pluck('task_id')->filter()->all();

        $tasks = $taskIds === [] ? collect() : PositionServiceTask::withTrashed()
            ->with(['subService' => fn ($query) => $query->withTrashed(), 'positionService' => fn ($query) => $query->withTrashed()])
            ->whereIn('id', $taskIds)
            ->get()
            ->keyBy('id');

        $standards = $this->standardsInForce($taskIds, $date);
        $errors = [];

        foreach ($items as $index => $item) {
            $taskId = $item['task_id'] ?? null;

            if ($taskId === null) {
                if (($item['sub_service_id'] ?? null) !== null) {
                    $errors["items.{$index}.task_id"] = __('daily-activities.task_required');
                } elseif ($this->settings->structuredEntryRequired()) {
                    $errors["items.{$index}.task_id"] = __('daily-activities.task_required');
                }
                $items[$index] = $this->unstructured($item);

                continue;
            }

            /** @var PositionServiceTask|null $task */
            $task = $tasks->get($taskId);
            $service = $task?->positionService;

            // The task must belong to the position and organization this log
            // is recorded under, through its own sub-service and service.
            if ($task === null || $service === null || $positionId === null
                || $service->position_id !== $positionId
                || ($organizationId !== null && $service->organization_id !== $organizationId)
                || (($item['sub_service_id'] ?? null) !== null && $item['sub_service_id'] !== $task->sub_service_id)
                || (($item['position_service_id'] ?? null) !== null && $item['position_service_id'] !== $task->position_service_id)) {
                $errors["items.{$index}.task_id"] = __('daily-activities.invalid_task');

                continue;
            }

            // A newly chosen task must be active all the way up; one already
            // recorded on this log stays valid so a returned day can be fixed.
            $retained = in_array($taskId, $retainedTaskIds, true);
            $inactive = $task->trashed() || ! $task->is_active
                || $task->subService === null || $task->subService->trashed() || ! $task->subService->is_active
                || $service->trashed() || ! $service->is_active;
            if ($inactive && ! $retained) {
                $errors["items.{$index}.task_id"] = __('daily-activities.service_inactive');

                continue;
            }

            $prior = isset($item['id']) ? $previous->get($item['id']) : null;
            $snapshot = $prior instanceof DailyActivityItem && $prior->task_id === $taskId && $prior->task_standard_id !== null
                ? $this->snapshotFromItem($prior)
                : $this->snapshotFromStandard($standards->get($taskId));

            if ($snapshot === null) {
                $errors["items.{$index}.task_id"] = __('daily-activities.no_standard', ['date' => $date->toDateString()]);

                continue;
            }

            $item['position_service_id'] = $task->position_service_id;
            $item['sub_service_id'] = $task->sub_service_id;
            $item['task_id'] = $task->id;
            $item['title'] = filled($item['title'] ?? null) ? $item['title'] : $task->name_en;

            try {
                $items[$index] = $this->measure($item, $snapshot, $index, $prior);
            } catch (ValidationException $exception) {
                $errors = [...$errors, ...$exception->errors()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $items;
    }

    /**
     * Recalculate the quality and aggregate scores of an item after the
     * reviewer records its actual quality.
     *
     * @return array<string, string|null>
     */
    public function rescoreWithQuality(DailyActivityItem $item, mixed $actualQuality): array
    {
        $snapshot = $this->snapshotFromItem($item);
        $measured = $this->measure([
            'quantity' => $item->quantity,
            'started_at' => $item->started_at,
            'ended_at' => $item->ended_at,
            'actual_quality' => $actualQuality,
        ], $snapshot, 0, null, reviewer: true);

        return array_intersect_key($measured, array_flip(['actual_quality', 'quantity_score', 'time_score', 'quality_score', 'task_score']));
    }

    /**
     * What the employee may choose on a date: the position's active services,
     * each with its active sub-services and tasks, and for each task the
     * standard in force on that date (read-only for the employee). A task
     * without an approved standard is listed so the employee can see it
     * exists, but it cannot be recorded until a standard is approved.
     *
     * Four bounded queries, whatever the size of the structure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(?string $positionId, ?string $organizationId, Carbon $date): array
    {
        if ($positionId === null) {
            return [];
        }

        $services = PositionService::query()
            ->where('position_id', $positionId)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name_en')
            ->get(['id', 'service_no', 'name_en', 'name_am']);

        $subServices = PositionServiceSubService::query()
            ->whereIn('position_service_id', $services->pluck('id'))
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'position_service_id', 'code', 'name_en', 'name_am'])
            ->groupBy('position_service_id');

        $tasks = PositionServiceTask::query()
            ->whereIn('sub_service_id', $subServices->flatten()->pluck('id'))
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'sub_service_id', 'code', 'name_en', 'name_am']);

        $standards = $this->standardsInForce($tasks->pluck('id')->all(), $date);
        $tasksBySub = $tasks->groupBy('sub_service_id');

        return $services->map(fn ($service): array => [
            'id' => $service->id,
            'code' => $service->service_no,
            'name_en' => $service->name_en,
            'name_am' => $service->name_am,
            'sub_services' => ($subServices->get($service->id) ?? collect())->map(fn ($sub): array => [
                'id' => $sub->id,
                'code' => $sub->code,
                'name_en' => $sub->name_en,
                'name_am' => $sub->name_am,
                'tasks' => ($tasksBySub->get($sub->id) ?? collect())->map(function (PositionServiceTask $task) use ($standards): array {
                    $snapshot = $this->snapshotFromStandard($standards->get($task->id));

                    return [
                        'id' => $task->id,
                        'code' => $task->code,
                        'name_en' => $task->name_en,
                        'name_am' => $task->name_am,
                        'standard' => $snapshot === null ? null : array_diff_key($snapshot, ['standard_id' => true, 'effective_from' => true]),
                    ];
                })->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }

    /** Does this item wait for the reviewer to record its quality? */
    public function needsReviewerQuality(DailyActivityItem $item): bool
    {
        return $item->task_standard_id !== null
            && $item->planned_quality !== null
            && ($item->standard_snapshot['quality_source'] ?? TaskQualitySource::Employee->value) === TaskQualitySource::Reviewer->value;
    }

    /**
     * @param  array<int, string>  $taskIds
     * @return Collection<string, PositionServiceTaskStandard>
     */
    private function standardsInForce(array $taskIds, Carbon $date): Collection
    {
        if ($taskIds === []) {
            return collect();
        }

        // Approval never leaves two approved versions overlapping, but the
        // latest start wins if data was ever entered by hand.
        return PositionServiceTaskStandard::query()
            ->whereIn('task_id', $taskIds)
            ->inForceOn($date->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('version_no')
            ->get()
            ->unique('task_id')
            ->keyBy('task_id');
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function measure(array $item, array $snapshot, int $index, ?DailyActivityItem $prior, bool $reviewer = false): array
    {
        $item['task_standard_id'] = $snapshot['standard_id'];
        $item['planned_quantity'] = $snapshot['planned_quantity'];
        $item['planned_time_minutes'] = $snapshot['planned_time_minutes'];
        $item['planned_quality'] = $snapshot['planned_quality'];
        $item['standard_snapshot'] = $snapshot;
        $item['unit_of_measure'] = $snapshot['quantity_unit'] ?? ($item['unit_of_measure'] ?? null);

        // Time taken is calculated from start and completion, never typed.
        $item['duration_minutes'] = null;
        $start = $item['started_at'] ?? null;
        $end = $item['ended_at'] ?? null;
        if ($start !== null && $end !== null) {
            $minutes = $this->minutes((string) $end) - $this->minutes((string) $start);
            if ($minutes <= 0) {
                // Same-day work only; overnight work has no approved rule.
                throw ValidationException::withMessages(["items.{$index}.ended_at" => __('daily-activities.completion_before_start')]);
            }
            $item['duration_minutes'] = $minutes;
        }

        // Who records quality is the standard's decision, not the employee's.
        $qualityByReviewer = ($snapshot['quality_source'] ?? TaskQualitySource::Employee->value) === TaskQualitySource::Reviewer->value;
        if ($qualityByReviewer && ! $reviewer) {
            $item['actual_quality'] = $prior?->actual_quality;
        }
        if ($snapshot['planned_quality'] === null) {
            $item['actual_quality'] = null;
        }

        $scores = [];
        try {
            if ($snapshot['planned_quantity'] !== null) {
                $scores['quantity'] = blank($item['quantity'] ?? null) ? null : $this->calculator->quantityScore($item['quantity'], $snapshot['planned_quantity']);
            }
            if ($snapshot['planned_time_minutes'] !== null) {
                $scores['time'] = $item['duration_minutes'] === null ? null : $this->calculator->timeScore($snapshot['planned_time_minutes'], $item['duration_minutes']);
            }
            if ($snapshot['planned_quality'] !== null) {
                $scores['quality'] = blank($item['actual_quality'] ?? null) ? null : $this->calculator->qualityScore($item['actual_quality'], $snapshot['planned_quality']);
            }
        } catch (InvalidArgumentException) {
            // Planned values are checked to be above zero when a standard is
            // saved, and time taken above zero here, so this is a backstop.
            throw ValidationException::withMessages(["items.{$index}.task_id" => __('daily-activities.score_not_computable')]);
        }

        $task = $this->calculator->taskScore($scores, $this->settings->taskScoreRule());

        $item['quantity_score'] = $this->stored($scores['quantity'] ?? null);
        $item['time_score'] = $this->stored($scores['time'] ?? null);
        $item['quality_score'] = $this->stored($scores['quality'] ?? null);
        $item['task_score'] = $this->stored($task);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function unstructured(array $item): array
    {
        return [
            ...$item,
            'sub_service_id' => null,
            'task_id' => null,
            'task_standard_id' => null,
            'planned_quantity' => null,
            'planned_time_minutes' => null,
            'planned_quality' => null,
            'standard_snapshot' => null,
            'actual_quality' => null,
            'quantity_score' => null,
            'time_score' => null,
            'quality_score' => null,
            'task_score' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function snapshotFromStandard(?PositionServiceTaskStandard $standard): ?array
    {
        if ($standard === null) {
            return null;
        }

        return [
            'standard_id' => $standard->id,
            'version_no' => $standard->version_no,
            'standard_measure' => $standard->standard_measure,
            'bpr_reference' => $standard->bpr_reference,
            'planned_quantity' => $standard->planned_quantity,
            'quantity_unit' => $standard->quantity_unit,
            'planned_time_minutes' => $standard->planned_time_minutes,
            'planned_quality' => $standard->planned_quality,
            'quality_unit' => $standard->quality_unit,
            'quality_measure' => $standard->quality_measure,
            'quality_source' => $standard->quality_source?->value ?? TaskQualitySource::Employee->value,
            'effective_from' => $standard->effective_from?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotFromItem(DailyActivityItem $item): array
    {
        return [
            ...($item->standard_snapshot ?? []),
            'standard_id' => $item->task_standard_id,
            'planned_quantity' => $item->planned_quantity,
            'planned_time_minutes' => $item->planned_time_minutes,
            'planned_quality' => $item->planned_quality,
        ];
    }

    private function stored(?string $score): ?string
    {
        return $score === null ? null : $this->calculator->round($score);
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hours * 60 + $minutes;
    }
}
