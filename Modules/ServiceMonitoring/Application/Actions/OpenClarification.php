<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\ClarificationStatus;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\ClarificationMessage;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** An institution asks its support officers a question (the forms' "ask the support experts", D22). */
final readonly class OpenClarification
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    /** @param array{subject: string, body: string, period_id?: ?string, submission_id?: ?string, section?: ?int} $data */
    public function execute(Participant $participant, array $data, User $actor): Clarification
    {
        $submissionId = $data['submission_id'] ?? null;
        $submission = $submissionId !== null ? Submission::query()->whereKey($submissionId)->where('participant_id', $participant->id)->first() : null;

        return DB::transaction(function () use ($participant, $data, $submission, $actor): Clarification {
            $clarification = Clarification::query()->create([
                'participant_id' => $participant->id,
                'period_id' => $submission?->period_id ?? ($data['period_id'] ?? null),
                'submission_id' => $submission?->id,
                'section' => $data['section'] ?? null,
                'subject' => $data['subject'],
                'status' => ClarificationStatus::Open,
                'opened_by' => $actor->id,
            ]);
            ClarificationMessage::query()->create(['clarification_id' => $clarification->id, 'author_id' => $actor->id, 'body' => $data['body']]);
            $this->audit->record(AuditEventType::ServiceMonitoringClarificationOpened, $actor, $clarification, $participant->organization_id, ['section' => $clarification->section]);

            return $clarification;
        });
    }
}
