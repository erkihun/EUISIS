<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\NotificationPublisher;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Ports\UploadInspector;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUp;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUpUpdate;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;

/**
 * Follow-up actions arising from a report. They have their own lifecycle
 * (open → in progress → done → closed, or cancelled) and never change the
 * submission's compliance history.
 */
final readonly class ManageFollowUps
{
    public const STATUSES = ['open', 'in_progress', 'done', 'closed', 'cancelled'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function __construct(
        private ReportingAccessResolver $access,
        private NotificationPublisher $notifications,
        private UploadInspector $inspector,
        private AuditRecorder $audit,
        private ReportingSettings $settings,
    ) {}

    /** @param array{title: string, description: string, owner_user_id?: ?int, priority?: string, due_at: DateTimeImmutable} $data */
    public function create(ActorContext $actor, string $obligationId, array $data): FollowUp
    {
        $this->settings->assertWritable();
        $obligation = Obligation::query()->findOrFail($obligationId);
        // Raised by a reviewer of the receiving side.
        if (! ReportingGuard::onReceivingSide($actor, $obligation) || ! ($actor->can(ReportingRoles::REVIEW) || $actor->can(ReportingRoles::APPROVE))) {
            throw new ReportingAccessDenied(ReportingRoles::REVIEW);
        }
        $ownerId = $data['owner_user_id'] ?? $obligation->accountable_user_id ?? $obligation->preparer_user_id;
        $owner = $ownerId === null ? null : ($this->access->users([(int) $ownerId])[(int) $ownerId] ?? throw new WorkflowViolation('unknown_user'));

        $followUp = FollowUp::query()->create([
            'obligation_id' => $obligation->id, 'submission_id' => $obligation->latest_submission_id, 'organization_id' => $obligation->organization_id,
            'title' => Str::limit(trim(strip_tags($data['title'])), 250, ''), 'description' => trim(strip_tags($data['description'])),
            'owner_user_id' => $owner?->id, 'owner_name' => $owner?->name,
            'priority' => in_array($data['priority'] ?? 'normal', self::PRIORITIES, true) ? ($data['priority'] ?? 'normal') : 'normal',
            'due_at' => CarbonImmutable::instance($data['due_at'])->utc(), 'status' => 'open', 'progress' => 0,
            'created_by' => $actor->userId, 'created_by_name' => $actor->name,
        ]);
        $this->audit->record('follow_up_saved', $actor, 'reporting.follow_up', $followUp->id, $obligation->organization_id, ['action' => 'created', 'owner_user_id' => $owner?->id], obligationId: $obligation->id);
        if ($owner !== null) {
            $this->notifications->publish('workflow', 'follow_up_assigned', "follow_up:{$followUp->id}:assigned", [$owner->id], ['title' => $followUp->title], $obligation->id);
        }

        return $followUp;
    }

    /**
     * A progress note, by the owner, the reporting institution or the
     * receiving side; optionally with one evidence file. Closing (accepting
     * the action as complete) and cancelling are the receiving side's.
     *
     * @param  array{progress: int, status: string, note: string}  $data
     * @param  array{path: string, name: string}|null  $evidence
     */
    public function update(ActorContext $actor, string $followUpId, array $data, ?array $evidence = null): FollowUp
    {
        $this->settings->assertWritable();
        if (! in_array($data['status'], self::STATUSES, true) || trim($data['note']) === '') {
            throw new WorkflowViolation('follow_up_invalid');
        }
        $stored = null;
        if ($evidence !== null) {
            $this->inspector->inspect($evidence['path'], $evidence['name'], 'evidence');
            $disk = (string) config('reporting.disk');
            $stored = ['disk' => $disk, 'path' => sprintf('reporting/follow-ups/%s/%s.%s', $followUpId, Str::uuid7()->toString(), strtolower(pathinfo($evidence['name'], PATHINFO_EXTENSION)))];
            $stream = fopen($evidence['path'], 'rb');
            Storage::disk($disk)->put($stored['path'], $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return DB::transaction(function () use ($actor, $followUpId, $data, $evidence, $stored): FollowUp {
            $followUp = FollowUp::query()->lockForUpdate()->findOrFail($followUpId);
            $obligation = Obligation::query()->findOrFail($followUp->obligation_id);
            $receiving = ReportingGuard::onReceivingSide($actor, $obligation) && ($actor->can(ReportingRoles::REVIEW) || $actor->can(ReportingRoles::APPROVE));
            $working = (int) $followUp->owner_user_id === $actor->userId || (ReportingGuard::onReportingSide($actor, $obligation) && $actor->can(ReportingRoles::PREPARE));
            if (! $receiving && ! $working) {
                throw new ReportingAccessDenied(ReportingRoles::PREPARE);
            }
            if (in_array($followUp->status, ['closed', 'cancelled'], true)) {
                throw new WorkflowViolation('follow_up_closed');
            }
            if (in_array($data['status'], ['closed', 'cancelled'], true) && ! $receiving) {
                throw new ReportingAccessDenied(ReportingRoles::REVIEW);
            }

            $now = CarbonImmutable::now('UTC');
            $progress = max(0, min(100, $data['progress']));
            FollowUpUpdate::query()->create([
                'follow_up_id' => $followUp->id, 'progress' => $progress, 'status' => $data['status'], 'note' => trim(strip_tags($data['note'])),
                'evidence_name' => $evidence === null ? null : Str::limit(basename(str_replace('\\', '/', $evidence['name'])), 250, ''),
                'evidence_disk' => $stored['disk'] ?? null, 'evidence_path' => $stored['path'] ?? null,
                'author_user_id' => $actor->userId, 'author_name' => $actor->name, 'created_at' => $now,
            ]);
            $closing = in_array($data['status'], ['closed', 'cancelled'], true);
            $followUp->forceFill([
                'status' => $data['status'], 'progress' => $progress,
                'closure_note' => $closing ? trim(strip_tags($data['note'])) : null,
                'closed_by' => $closing ? $actor->userId : null, 'closed_at' => $closing ? $now : null,
            ])->save();
            $this->audit->record('follow_up_saved', $actor, 'reporting.follow_up', $followUp->id, $followUp->organization_id, ['action' => 'updated', 'status' => $data['status'], 'progress' => $progress], obligationId: $obligation->id);

            return $followUp;
        });
    }
}
