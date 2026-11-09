<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ReminderService;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionStateMachine;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\ReviewComment;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** The reviewer returns a submitted report with a reason and optional line comments; the submitters hear at once (D23). */
final readonly class ReturnSubmission
{
    public function __construct(
        private SubmissionStateMachine $states,
        private ReminderService $reminders,
        private ServiceMonitoringAudit $audit,
    ) {}

    /** @param list<array{section: int, line_reference?: ?string, body: string}> $comments */
    public function execute(Submission $submission, User $actor, string $reason, array $comments = []): Submission
    {
        DB::transaction(function () use ($submission, $actor, $reason, $comments): void {
            foreach ($comments as $comment) {
                ReviewComment::query()->create([
                    'submission_id' => $submission->id,
                    'section' => $comment['section'],
                    'line_reference' => $comment['line_reference'] ?? null,
                    'body' => $comment['body'],
                    'author_id' => $actor->id,
                ]);
            }
            $submission->fill(['reviewed_at' => now(), 'reviewed_by' => $actor->id]);
            $this->states->move($submission, SubmissionStatus::Returned, $actor, $reason);

            $this->audit->record(AuditEventType::ServiceMonitoringReturned, $actor, $submission, $submission->organization_id,
                ['revision_no' => $submission->revision_no, 'comments' => count($comments)], $reason);
        });

        $this->reminders->notifyReturned($submission, $reason);

        return $submission;
    }
}
