<?php

declare(strict_types=1);

namespace Modules\Grievance\Application\Queries;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Modules\Grievance\Application\Services\GrievanceAudit;
use Modules\Grievance\Application\Services\GrievanceCaseAccessService;
use Modules\Grievance\Application\Services\GrievanceNotifier;
use Modules\Grievance\Application\Services\GrievanceTimeline;
use Modules\Grievance\Contracts\GrievanceCaseLinks;
use Modules\Grievance\Domain\Enums\GrievanceEvidenceStatus;
use Modules\Grievance\Domain\Models\Grievance;
use Modules\Grievance\Domain\Models\GrievanceEvidence;

/** A grievance case as the tribunal and court-ms see it (moved here from their services). */
final class GrievanceCaseLinksService implements GrievanceCaseLinks
{
    public function __construct(
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceAudit $audits,
        private readonly GrievanceNotifier $notifier,
    ) {}

    public function canView(User $user, string $grievanceId): bool
    {
        $grievance = Grievance::query()->find($grievanceId);

        return $grievance !== null && $this->access->canView($user, $grievance);
    }

    public function handlesCurrent(User $user, string $grievanceId): bool
    {
        $grievance = Grievance::query()->find($grievanceId);

        return $grievance !== null && $this->access->handlesCurrent($user, $grievance);
    }

    public function assignedIds(User $user): Builder
    {
        return $this->access->constrainAssigned(Grievance::query(), $user)->select('grievances.id')->toBase();
    }

    public function recordEvent(string $grievanceId, string $event, ?User $actor = null, array $data = []): void
    {
        $this->timeline->record(Grievance::query()->findOrFail($grievanceId), $event, $actor, $data);
    }

    public function audit(AuditEventType $event, ?User $actor, object $subject, array $new = [], ?array $old = null, ?string $reason = null): void
    {
        assert($subject instanceof Model);
        $this->audits->record($event, $actor, $subject, $new, $old, $reason);
    }

    public function notifyUsers(iterable $users, string $kind, string $grievanceId, ?string $url = null): void
    {
        $this->notifier->toUsers($users, $kind, Grievance::query()->findOrFail($grievanceId), $url);
    }

    public function deadlineAlertTarget(string $grievanceId): array
    {
        $grievance = Grievance::query()->find($grievanceId);
        if ($grievance === null) {
            return [null, null, []];
        }
        $stage = $grievance->currentStage;

        return [(string) $grievance->reference_number, '/grievances/cases/'.$grievance->getKey(), $stage === null ? [] : $this->notifier->stageHandlerUsers($stage)];
    }

    public function referableEvidence(string $grievanceId, int $maxBytes): array
    {
        return GrievanceEvidence::query()
            ->where('grievance_id', $grievanceId)
            ->whereIn('status', [GrievanceEvidenceStatus::Submitted->value, GrievanceEvidenceStatus::Accepted->value])
            ->where('mime_type', 'application/pdf')
            ->where('size_bytes', '<=', $maxBytes)
            ->orderBy('submitted_at')
            ->get(['id', 'title', 'original_name', 'size_bytes', 'submitted_at'])
            ->map(fn (GrievanceEvidence $e): array => [
                'id' => (string) $e->getKey(),
                'title' => $e->title,
                'original_name' => $e->original_name,
                'size_bytes' => $e->size_bytes === null ? null : (int) $e->size_bytes,
                'submitted_at' => $e->submitted_at?->toIso8601String(),
            ])->values()->all();
    }

    public function evidenceFiles(array $evidenceIds): array
    {
        if ($evidenceIds === []) {
            return [];
        }
        $out = [];
        foreach (GrievanceEvidence::query()->whereKey($evidenceIds)->get() as $evidence) {
            $disk = Storage::disk($evidence->disk);
            if (! $disk->exists($evidence->path)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($evidence->original_name ?: 'evidence.pdf'),
                'contents' => (string) $disk->get($evidence->path),
                'title' => (string) ($evidence->title ?: $evidence->original_name),
            ];
        }

        return $out;
    }
}
