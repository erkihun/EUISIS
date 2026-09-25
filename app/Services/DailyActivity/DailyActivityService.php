<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Models\EmployeePerformanceItem;
use App\Enums\Performance\AgreementStatus;
use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\DailyActivityDayStatus;
use App\Enums\DailyActivityHistoryAction;
use App\Enums\DailyActivityStatus;
use App\Models\DailyActivityAttachment;
use App\Models\DailyActivityItem;
use App\Models\DailyActivityLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\PositionService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Daily Activity workflow. Every state change goes through here.
 *
 * Invariants this class owns:
 *  - employee, organization, unit, position, status and reviewer are always
 *    derived server-side; nothing in a request can set them;
 *  - one header per employee per date, even under concurrent requests
 *    (unique index + row lock + duplicate-insert recovery);
 *  - approved logs are immutable except through reopen();
 *  - every transition writes workflow history and an audit entry.
 */
class DailyActivityService
{
    public const DISK = 'local';

    public const MAX_ITEMS = 30;

    public const MAX_ATTACHMENTS = 10;

    /** Item fields an employee may write. Anything else in the payload is dropped. */
    private const ITEM_FIELDS = [
        'activity_category',
        'position_service_id',
        'title',
        'description',
        'output_result',
        'progress_status',
        'started_at',
        'ended_at',
        'duration_minutes',
        'quantity',
        'unit_of_measure',
        'challenge_issue',
        'next_action',
        // EPMS: the employee's own KPI item this work is evidence for.
        'employee_performance_item_id',
    ];

    public function __construct(
        private readonly DailyActivitySettings $settings,
        private readonly DailyActivityCalendarService $calendar,
        private readonly EmployeeWorkContextResolver $context,
        private readonly WriteAuditLogAction $audit,
        private readonly DailyActivityNotifier $notifier,
    ) {}

    // ── Employee ────────────────────────────────────────────────────────────

    /**
     * Save (and optionally submit) the employee's log for a date.
     *
     * Submitting an already-submitted log is a no-op that returns it, so a
     * double-clicked Submit does not fail or duplicate anything.
     *
     * @param  array{items?: array<int, array<string, mixed>>, late_reason?: ?string}  $data
     */
    public function save(User $actor, Employee $employee, Carbon $date, array $data, bool $submit): DailyActivityLog
    {
        $this->assertEnabled();

        $existing = $this->findLog($employee, $date);

        if ($submit && $existing !== null && $existing->status->countsAsSubmitted()) {
            return $existing;
        }

        $isCorrection = $existing?->status === DailyActivityStatus::ReturnedForCorrection;

        if (! $isCorrection) {
            $this->assertDateOpenForEntry($employee, $date);
        }

        $assignment = $this->context->assignmentOn($employee, $date);
        if ($assignment === null && $existing === null) {
            throw ValidationException::withMessages(['date' => __('daily-activities.not_assigned')]);
        }

        $items = $this->normalizeItems($data['items'] ?? []);
        $positionId = $existing?->position_id ?? $assignment?->position_id;
        $this->assertTasksBelongToPosition($items, $positionId);
        $performanceLinks = $this->performanceLinks($items, $employee, $date);

        return DB::transaction(function () use ($actor, $employee, $date, $data, $submit, $items, $assignment, $performanceLinks): DailyActivityLog {
            [$log, $created] = $this->lockOrCreate($actor, $employee, $date, $assignment);

            if ($submit && $log->status->countsAsSubmitted()) {
                return $log;
            }

            if (! $log->status->isEmployeeEditable()) {
                throw ValidationException::withMessages([
                    'status' => __('daily-activities.locked', ['status' => __("daily-activities.status.{$log->status->value}")]),
                ]);
            }

            $before = $this->itemSnapshot($log);
            $this->syncItems($log, $items, $performanceLinks);

            if (array_key_exists('late_reason', $data)) {
                $log->late_reason = $this->nullableText($data['late_reason'] ?? null);
            }
            $log->save();

            $after = $this->itemSnapshot($log);

            if ($created || $before !== $after) {
                $this->history($log, $created ? DailyActivityHistoryAction::Created : DailyActivityHistoryAction::Updated, $actor, $log->status, $log->status);
                $this->audit->execute(
                    $created ? AuditEventType::DailyActivityCreated : AuditEventType::DailyActivityUpdated,
                    $actor,
                    $log,
                    $log->organization_id,
                    $created ? null : ['items' => $this->auditItemSummary($before)],
                    ['activity_date' => $log->activityDateString(), 'items' => $this->auditItemSummary($after)],
                    request: request(),
                );
            }

            if ($submit) {
                $this->submitLocked($actor, $log);
            }

            return $log->fresh(['items']);
        });
    }

    // ── Reviewer ────────────────────────────────────────────────────────────

    /** @param array<string, string> $itemNotes item id => note (optional) */
    public function approve(User $reviewer, DailyActivityLog $log, ?string $comment, array $itemNotes = []): DailyActivityLog
    {
        $log = DB::transaction(function () use ($reviewer, $log, $comment, $itemNotes): DailyActivityLog {
            $locked = $this->lock($log);
            $this->assertAwaitingReview($locked);

            $from = $locked->status;
            $this->applyItemNotes($locked, $itemNotes);
            $this->transition($locked, DailyActivityStatus::Approved, [
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->getKey(),
                'review_comment' => $this->nullableText($comment),
            ]);

            $this->history($locked, DailyActivityHistoryAction::Approved, $reviewer, $from, $locked->status, $comment);
            $this->auditTransition(AuditEventType::DailyActivityApproved, $reviewer, $locked, $from, $comment);

            return $locked;
        });

        if ($this->settings->notifyOnApproval()) {
            $this->notifier->notifyEmployee($log->employee, 'approved', $log->activityDateString());
        }

        return $log;
    }

    /** @param array<string, string> $itemNotes item id => note (optional) */
    public function returnForCorrection(User $reviewer, DailyActivityLog $log, string $comment, array $itemNotes = []): DailyActivityLog
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => __('daily-activities.comment_required')]);
        }

        $log = DB::transaction(function () use ($reviewer, $log, $comment, $itemNotes): DailyActivityLog {
            $locked = $this->lock($log);
            $this->assertAwaitingReview($locked);

            $from = $locked->status;
            $this->applyItemNotes($locked, $itemNotes);
            $this->transition($locked, DailyActivityStatus::ReturnedForCorrection, [
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->getKey(),
                'review_comment' => $comment,
            ]);

            $this->history($locked, DailyActivityHistoryAction::Returned, $reviewer, $from, $locked->status, $comment);
            $this->auditTransition(AuditEventType::DailyActivityReturned, $reviewer, $locked, $from, $comment);

            return $locked;
        });

        $this->notifier->notifyEmployee($log->employee, 'returned', $log->activityDateString(), $comment);

        return $log;
    }

    /**
     * The only way out of APPROVED. Returns the log to the employee for
     * correction with a recorded reason; the approval stays in history.
     */
    public function reopen(User $actor, DailyActivityLog $log, string $reason): DailyActivityLog
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('daily-activities.reason_required')]);
        }

        $log = DB::transaction(function () use ($actor, $log, $reason): DailyActivityLog {
            $locked = $this->lock($log);

            if ($locked->status !== DailyActivityStatus::Approved) {
                throw ValidationException::withMessages(['status' => __('daily-activities.not_approved')]);
            }

            $from = $locked->status;
            $this->transition($locked, DailyActivityStatus::ReturnedForCorrection, [
                'reopened_at' => now(),
                'reopened_by' => $actor->getKey(),
                'reopen_reason' => $reason,
                'review_comment' => $reason,
            ]);

            $this->history($locked, DailyActivityHistoryAction::Reopened, $actor, $from, $locked->status, $reason);
            $this->auditTransition(AuditEventType::DailyActivityReopened, $actor, $locked, $from, $reason);

            return $locked;
        });

        $this->notifier->notifyEmployee($log->employee, 'reopened', $log->activityDateString(), $reason);

        return $log;
    }

    // ── Evidence ────────────────────────────────────────────────────────────

    public function addAttachment(User $actor, DailyActivityLog $log, UploadedFile $file, ?string $itemId = null): DailyActivityAttachment
    {
        if (! $this->settings->evidenceAttachmentsEnabled()) {
            throw ValidationException::withMessages(['file' => __('daily-activities.attachments_disabled')]);
        }

        return DB::transaction(function () use ($actor, $log, $file, $itemId): DailyActivityAttachment {
            $locked = $this->lock($log);

            if (! $locked->status->isEmployeeEditable()) {
                throw ValidationException::withMessages(['file' => __('daily-activities.locked', ['status' => __("daily-activities.status.{$locked->status->value}")])]);
            }

            if ($locked->attachments()->count() >= self::MAX_ATTACHMENTS) {
                throw ValidationException::withMessages(['file' => __('daily-activities.attachment_limit', ['max' => self::MAX_ATTACHMENTS])]);
            }

            // An item id from the request is honoured only if it is on this log.
            $itemId = $itemId !== null && $locked->items()->whereKey($itemId)->exists() ? $itemId : null;

            // Stored name is random; the extension comes from the sniffed MIME
            // type, never from the client's file name.
            $extension = $file->guessExtension() ?: 'bin';
            $path = $file->storeAs(
                'daily-activity/'.$locked->employee_id.'/'.$locked->id,
                Str::uuid()->toString().'.'.$extension,
                self::DISK,
            );

            $attachment = $locked->attachments()->create([
                'daily_activity_item_id' => $itemId,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                'file_path' => $path,
                'mime_type' => (string) $file->getMimeType(),
                'file_size' => (int) $file->getSize(),
                'uploaded_by' => $actor->getKey(),
            ]);

            $this->history($locked, DailyActivityHistoryAction::AttachmentUploaded, $actor, $locked->status, $locked->status, $attachment->original_name);
            $this->audit->execute(
                AuditEventType::DailyActivityEvidenceUploaded,
                $actor,
                $locked,
                $locked->organization_id,
                null,
                ['attachment_id' => $attachment->id, 'original_name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'file_size' => $attachment->file_size],
                request: request(),
            );

            return $attachment;
        });
    }

    public function deleteAttachment(User $actor, DailyActivityAttachment $attachment): void
    {
        DB::transaction(function () use ($actor, $attachment): void {
            $locked = $this->lock($attachment->log);

            if (! $locked->status->isEmployeeEditable()) {
                throw ValidationException::withMessages(['file' => __('daily-activities.locked', ['status' => __("daily-activities.status.{$locked->status->value}")])]);
            }

            $meta = ['attachment_id' => $attachment->id, 'original_name' => $attachment->original_name];
            $path = $attachment->file_path;
            $attachment->delete();

            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));

            $this->history($locked, DailyActivityHistoryAction::AttachmentDeleted, $actor, $locked->status, $locked->status, $meta['original_name']);
            $this->audit->execute(AuditEventType::DailyActivityEvidenceDeleted, $actor, $locked, $locked->organization_id, $meta, null, request: request());
        });
    }

    // ── Rules ───────────────────────────────────────────────────────────────

    /**
     * Future dates, non-working days and dates outside the backdating window
     * are closed. Returned logs skip this: correcting a returned day is
     * always allowed, however old it is.
     */
    public function assertDateOpenForEntry(Employee $employee, Carbon $date): void
    {
        $today = $this->settings->today();
        $day = $date->toDateString();

        if ($day > $today->toDateString()) {
            throw ValidationException::withMessages(['date' => __('daily-activities.future_date')]);
        }

        $status = $this->calendar->dayStatus($employee, $date);

        match ($status) {
            DailyActivityDayStatus::NotEmployed => throw ValidationException::withMessages(['date' => __('daily-activities.not_employed')]),
            DailyActivityDayStatus::NotAssigned => throw ValidationException::withMessages(['date' => __('daily-activities.not_assigned')]),
            DailyActivityDayStatus::Weekend, DailyActivityDayStatus::PublicHoliday, DailyActivityDayStatus::Leave => throw ValidationException::withMessages([
                'date' => __('daily-activities.not_working_day', ['reason' => __("daily-activities.status.day_{$status->value}")]),
            ]),
            default => null,
        };

        if ($day < $today->toDateString()) {
            if (! $this->settings->allowBackdatedSubmission()) {
                throw ValidationException::withMessages(['date' => __('daily-activities.backdate_not_allowed')]);
            }

            $daysBack = (int) Carbon::parse($day)->diffInDays(Carbon::parse($today->toDateString()));
            if ($daysBack > $this->settings->maxBackdateDays()) {
                throw ValidationException::withMessages(['date' => __('daily-activities.backdate_limit', ['days' => $this->settings->maxBackdateDays()])]);
            }
        }
    }

    public function isLate(Carbon $activityDate, ?Carbon $at = null): bool
    {
        $at ??= $this->settings->now();

        return $at->greaterThan($this->settings->deadlineFor($activityDate));
    }

    public function findLog(Employee $employee, Carbon $date): ?DailyActivityLog
    {
        return DailyActivityLog::query()
            ->where('employee_id', $employee->id)
            ->onDate($date->toDateString())
            ->first();
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function submitLocked(User $actor, DailyActivityLog $log): void
    {
        $log->load('items');
        $this->assertSubmittable($log);

        $isResubmission = $log->status === DailyActivityStatus::ReturnedForCorrection;
        $from = $log->status;
        $attributes = [
            'submitted_at' => now(),
            'submitted_by' => $actor->getKey(),
            'submission_count' => $log->submission_count + 1,
        ];

        // Lateness is judged once, on first submission. A correction the
        // reviewer asked for is not a late submission.
        if (! $isResubmission && $log->first_submitted_at === null) {
            $late = $this->isLate($log->activity_date);

            if ($late && $this->settings->rejectLateSubmission()) {
                throw ValidationException::withMessages(['late_reason' => __('daily-activities.late_rejected', ['deadline' => $this->settings->submissionDeadline()])]);
            }

            if ($late && $this->settings->requireLateReason() && blank($log->late_reason)) {
                throw ValidationException::withMessages(['late_reason' => __('daily-activities.late_reason_required', ['deadline' => $this->settings->submissionDeadline()])]);
            }

            $attributes['is_late'] = $late;
            $attributes['first_submitted_at'] = now();
        }

        $this->transition($log, $isResubmission ? DailyActivityStatus::Resubmitted : DailyActivityStatus::Submitted, $attributes);

        $action = $isResubmission ? DailyActivityHistoryAction::Resubmitted : DailyActivityHistoryAction::Submitted;
        $this->history($log, $action, $actor, $from, $log->status, null, $this->itemSnapshot($log));
        $this->audit->execute(
            $isResubmission ? AuditEventType::DailyActivityResubmitted : AuditEventType::DailyActivitySubmitted,
            $actor,
            $log,
            $log->organization_id,
            ['status' => $from->value],
            ['status' => $log->status->value, 'is_late' => $log->is_late, 'items' => $log->items->count(), 'submission_count' => $log->submission_count],
            request: request(),
        );
    }

    private function assertSubmittable(DailyActivityLog $log): void
    {
        if ($log->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => __('daily-activities.items_required')]);
        }

        $errors = [];
        foreach ($log->items->values() as $index => $item) {
            if (blank($item->title)) {
                $errors["items.{$index}.title"] = __('validation.required', ['attribute' => __('daily-activities.fields.title')]);
            }
            if (blank($item->description)) {
                $errors["items.{$index}.description"] = __('validation.required', ['attribute' => __('daily-activities.fields.description')]);
            }
            if ($this->settings->requireOutputResult() && blank($item->output_result)) {
                $errors["items.{$index}.output_result"] = __('daily-activities.output_required');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertAwaitingReview(DailyActivityLog $log): void
    {
        if (! $log->status->isAwaitingReview()) {
            throw ValidationException::withMessages(['status' => __('daily-activities.not_awaiting_review')]);
        }
    }

    private function assertEnabled(): void
    {
        if (! $this->settings->enabled()) {
            throw ValidationException::withMessages(['date' => __('daily-activities.module_disabled')]);
        }
    }

    /**
     * EPMS link: an activity may point at one of the employee's OWN KPI items,
     * in an agreement that is agreed/active on the activity date. The link is
     * evidence and progress for that KPI — never a score by itself.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, array{id: string, kpi_id: string, objective_id: ?string}>
     */
    private function performanceLinks(array $items, Employee $employee, Carbon $date): array
    {
        $ids = array_values(array_unique(array_filter(array_column($items, 'employee_performance_item_id'))));
        if ($ids === []) {
            return [];
        }

        $valid = EmployeePerformanceItem::query()->whereIn('id', $ids)->where('is_current', true)
            ->whereHas('agreement', fn ($q) => $q->where('employee_id', $employee->id)
                ->whereIn('status', [AgreementStatus::Agreed->value, AgreementStatus::Active->value, AgreementStatus::UnderReview->value])
                ->where('effective_from', '<=', $date->toDateString())->where('effective_to', '>=', $date->toDateString()))
            ->get(['id', 'kpi_id', 'objective_id']);

        if ($valid->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => __('performance.errors.item_not_in_agreement')]);
        }

        return $valid->mapWithKeys(fn (EmployeePerformanceItem $i) => [$i->id => ['id' => $i->id, 'kpi_id' => $i->kpi_id, 'objective_id' => $i->objective_id]])->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertTasksBelongToPosition(array $items, ?string $positionId): void
    {
        $serviceIds = array_values(array_unique(array_filter(array_column($items, 'position_service_id'))));

        if ($serviceIds === []) {
            return;
        }

        $valid = $positionId === null ? 0 : PositionService::query()
            ->whereIn('id', $serviceIds)
            ->where('position_id', $positionId)
            ->count();

        if ($valid !== count($serviceIds)) {
            throw ValidationException::withMessages(['items' => __('daily-activities.invalid_task')]);
        }
    }

    /**
     * Find the header under a row lock, or create it with the context
     * snapshot.
     *
     * Creation is an INSERT ... IGNORE / ON CONFLICT DO NOTHING against the
     * (employee_id, activity_date) unique index, followed by a locked read.
     * If a concurrent request committed the same header first, the insert
     * simply affects no row and this request continues on the existing one:
     * no duplicate, no exception, and no aborted transaction on PostgreSQL.
     *
     * @return array{0: DailyActivityLog, 1: bool}
     */
    private function lockOrCreate(User $actor, Employee $employee, Carbon $date, ?EmployeeAssignment $assignment): array
    {
        $find = fn (): ?DailyActivityLog => DailyActivityLog::query()
            ->where('employee_id', $employee->id)
            ->onDate($date->toDateString())
            ->lockForUpdate()
            ->first();

        if (($existing = $find()) !== null) {
            return [$existing, false];
        }

        $model = new DailyActivityLog;
        $now = $model->fromDateTime(now());

        $inserted = DailyActivityLog::query()->insertOrIgnore([
            'id' => $model->newUniqueId(),
            'employee_id' => $employee->id,
            'employee_assignment_id' => $assignment->id,
            'organization_id' => $assignment->organization_id,
            'organization_unit_id' => $assignment->organization_unit_id,
            'position_id' => $assignment->position_id,
            // Same storage format Eloquent's date cast would write.
            'activity_date' => $model->fromDateTime(Carbon::parse($date->toDateString())),
            'status' => DailyActivityStatus::Draft->value,
            'created_by' => $actor->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $log = $find();

        if ($log === null) {
            throw new \RuntimeException('Daily activity header could not be created or found.');
        }

        return [$log, $inserted === 1];
    }

    private function lock(DailyActivityLog $log): DailyActivityLog
    {
        return DailyActivityLog::query()->whereKey($log->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function transition(DailyActivityLog $log, DailyActivityStatus $to, array $attributes = []): void
    {
        if (! $log->status->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => __('daily-activities.locked', ['status' => __("daily-activities.status.{$log->status->value}")])]);
        }

        $log->forceFill(['status' => $to, ...$attributes])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(DailyActivityLog $log, array $items, array $performanceLinks = []): void
    {
        $existing = $log->items()->get()->keyBy('id');
        $keep = [];

        foreach (array_values($items) as $index => $data) {
            $id = $data['id'] ?? null;
            $attributes = [...Arr::only($data, self::ITEM_FIELDS), 'sort_order' => $index];
            // KPI and objective come from the verified agreement item, never from the browser.
            $link = $performanceLinks[$data['employee_performance_item_id'] ?? ''] ?? null;
            $attributes['employee_performance_item_id'] = $link['id'] ?? null;

            // An id is only honoured when it already belongs to THIS log.
            $item = $id !== null ? $existing->get($id) : null;

            if ($item instanceof DailyActivityItem) {
                $item->fill($attributes);
            } else {
                $item = $log->items()->make($attributes);
            }
            $item->forceFill(['kpi_id' => $link['kpi_id'] ?? null, 'performance_objective_id' => $link['objective_id'] ?? null])->save();

            $keep[] = $item->id;
        }

        $log->items()->whereNotIn('id', $keep)->delete();
        $log->unsetRelation('items');
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        // validated() assembles wildcard arrays in RULE order, so items.1 can
        // precede items.0 when only item 1 has an optional field. The
        // employee's order is the submitted index.
        ksort($items, SORT_NUMERIC);

        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, 0, self::MAX_ITEMS);
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = Arr::only($item, [...self::ITEM_FIELDS, 'id']);

            foreach (['title', 'description', 'output_result', 'unit_of_measure', 'challenge_issue', 'next_action'] as $field) {
                $row[$field] = $this->nullableText($row[$field] ?? null);
            }
            $row['title'] = (string) ($row['title'] ?? '');
            foreach (['activity_category', 'position_service_id', 'started_at', 'ended_at', 'id'] as $field) {
                $row[$field] = blank($row[$field] ?? null) ? null : (string) $row[$field];
            }
            $row['duration_minutes'] = blank($row['duration_minutes'] ?? null) ? null : (int) $row['duration_minutes'];
            $row['quantity'] = blank($row['quantity'] ?? null) ? null : $row['quantity'];

            if ($row['duration_minutes'] === null && $row['started_at'] !== null && $row['ended_at'] !== null) {
                $minutes = Carbon::createFromFormat('H:i', substr($row['started_at'], 0, 5))
                    ->diffInMinutes(Carbon::createFromFormat('H:i', substr($row['ended_at'], 0, 5)), false);
                $row['duration_minutes'] = $minutes > 0 ? (int) $minutes : null;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /** @param array<string, string> $notes */
    private function applyItemNotes(DailyActivityLog $log, array $notes): void
    {
        foreach ($notes as $itemId => $note) {
            $log->items()->whereKey($itemId)->update(['reviewer_note' => $this->nullableText($note)]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function itemSnapshot(DailyActivityLog $log): array
    {
        return $log->items()->get()->map(fn (DailyActivityItem $item): array => [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'output_result' => $item->output_result,
            'progress_status' => $item->progress_status?->value,
            'activity_category' => $item->activity_category?->value,
            'position_service_id' => $item->position_service_id,
            'started_at' => $item->started_at,
            'ended_at' => $item->ended_at,
            'duration_minutes' => $item->duration_minutes,
            'quantity' => $item->quantity,
            'unit_of_measure' => $item->unit_of_measure,
            'challenge_issue' => $item->challenge_issue,
            'next_action' => $item->next_action,
        ])->all();
    }

    /**
     * Audit carries what changed at a glance (titles and statuses), not
     * whole narratives: the history snapshot holds the full text.
     *
     * @param  array<int, array<string, mixed>>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function auditItemSummary(array $snapshot): array
    {
        return array_map(static fn (array $item): array => [
            'title' => $item['title'],
            'progress_status' => $item['progress_status'],
        ], $snapshot);
    }

    /** @param array<int, array<string, mixed>>|null $snapshot */
    private function history(DailyActivityLog $log, DailyActivityHistoryAction $action, ?User $actor, ?DailyActivityStatus $from, ?DailyActivityStatus $to, ?string $comment = null, ?array $snapshot = null): void
    {
        $log->histories()->create([
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_user_id' => $actor?->getKey(),
            'comment' => $comment,
            'snapshot' => $snapshot,
        ]);
    }

    private function auditTransition(AuditEventType $event, User $actor, DailyActivityLog $log, DailyActivityStatus $from, ?string $comment): void
    {
        $this->audit->execute(
            $event,
            $actor,
            $log,
            $log->organization_id,
            ['status' => $from->value],
            ['status' => $log->status->value, 'activity_date' => $log->activityDateString(), 'employee_id' => $log->employee_id],
            $comment,
            request(),
        );
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
