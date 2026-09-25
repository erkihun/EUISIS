<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Models\DailyActivityAttachment;
use App\Models\DailyActivityHistory;
use App\Models\DailyActivityItem;
use App\Models\DailyActivityLog;
use App\Models\Employee;

/**
 * Shapes logs for Inertia. Dates leave as Gregorian ISO strings; the browser
 * renders them in the Ethiopian or Gregorian calendar by locale, so switching
 * language never changes which record a date refers to.
 */
class DailyActivityPresenter
{
    /** Eager loads every presenter method below relies on. */
    public const SUMMARY_WITH = [
        'employee:id,employee_number,full_name,name_en',
        'organization:id,name_en,name_am',
        'organizationUnit:id,name_en,name_am',
        'position:id,title_en,title_am',
        'reviewer:id,name',
    ];

    /** @return array<string, mixed> */
    public function summary(DailyActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'activity_date' => $log->activityDateString(),
            'status' => $log->status->value,
            'is_late' => $log->is_late,
            'submitted_at' => $log->submitted_at?->toIso8601String(),
            'reviewed_at' => $log->reviewed_at?->toIso8601String(),
            'reviewer' => $log->relationLoaded('reviewer') ? $log->reviewer?->name : null,
            'items_count' => (int) ($log->items_count ?? ($log->relationLoaded('items') ? $log->items->count() : 0)),
            'employee' => $this->employee($log->employee),
            'organization' => $this->named($log->organization?->name_en, $log->organization?->name_am),
            'organization_unit' => $this->named($log->organizationUnit?->name_en, $log->organizationUnit?->name_am),
            'position' => $this->named($log->position?->title_en, $log->position?->title_am),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(DailyActivityLog $log): array
    {
        $log->loadMissing([
            ...self::SUMMARY_WITH,
            'items.positionService:id,name_en,name_am',
            'attachments',
            'histories.actor:id,name',
            'reopener:id,name',
            'submitter:id,name',
        ]);

        return [
            ...$this->summary($log),
            'late_reason' => $log->late_reason,
            'review_comment' => $log->review_comment,
            'first_submitted_at' => $log->first_submitted_at?->toIso8601String(),
            'submission_count' => $log->submission_count,
            'submitted_by' => $log->submitter?->name,
            'reopened_at' => $log->reopened_at?->toIso8601String(),
            'reopened_by' => $log->reopener?->name,
            'reopen_reason' => $log->reopen_reason,
            'items' => $log->items->map(fn (DailyActivityItem $item): array => $this->item($item))->values()->all(),
            'attachments' => $log->attachments->map(fn (DailyActivityAttachment $attachment): array => $this->attachment($attachment))->values()->all(),
            'history' => $log->histories->map(fn (DailyActivityHistory $history): array => [
                'id' => $history->id,
                'action' => $history->action->value,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'actor' => $history->actor?->name,
                'comment' => $history->comment,
                'created_at' => $history->created_at?->toIso8601String(),
                'items' => $history->snapshot,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function item(DailyActivityItem $item): array
    {
        return [
            'id' => $item->id,
            'activity_category' => $item->activity_category?->value,
            'position_service_id' => $item->position_service_id,
            'employee_performance_item_id' => $item->employee_performance_item_id,
            'position_service' => $item->relationLoaded('positionService') && $item->positionService
                ? $this->named($item->positionService->name_en, $item->positionService->name_am)
                : null,
            'title' => $item->title,
            'description' => $item->description,
            'output_result' => $item->output_result,
            'progress_status' => $item->progress_status?->value,
            'started_at' => $item->started_at ? substr((string) $item->started_at, 0, 5) : null,
            'ended_at' => $item->ended_at ? substr((string) $item->ended_at, 0, 5) : null,
            'duration_minutes' => $item->duration_minutes,
            'quantity' => $item->quantity,
            'unit_of_measure' => $item->unit_of_measure,
            'challenge_issue' => $item->challenge_issue,
            'next_action' => $item->next_action,
            'reviewer_note' => $item->reviewer_note,
        ];
    }

    /** @return array<string, mixed> */
    public function attachment(DailyActivityAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'item_id' => $attachment->daily_activity_item_id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'download_url' => route('daily-activities.attachments.download', $attachment),
        ];
    }

    /** @return array<string, mixed>|null */
    public function employee(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'name_en' => $employee->name_en,
        ];
    }

    /** @return array{name_en: string, name_am: ?string}|null */
    public function named(?string $en, ?string $am): ?array
    {
        return $en === null && $am === null ? null : ['name_en' => (string) ($en ?? $am), 'name_am' => $am];
    }
}
