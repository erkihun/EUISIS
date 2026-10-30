<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Models\ReviewComment;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** A reviewer's comment against a section or line of a submission, shown on the entry screen beside that line. */
final readonly class AddReviewComment
{
    public function __construct(private SubmissionGuard $guard, private ServiceMonitoringAudit $audit) {}

    public function execute(Submission $submission, int $section, ?string $lineReference, string $body, User $actor): ReviewComment
    {
        $this->guard->assertPeriodWritable($submission->period);
        $comment = ReviewComment::query()->create([
            'submission_id' => $submission->id, 'section' => $section, 'line_reference' => $lineReference, 'body' => $body, 'author_id' => $actor->id,
        ]);
        $this->audit->record(AuditEventType::ServiceMonitoringReviewCommentAdded, $actor, $comment, $submission->organization_id, ['submission_id' => $submission->id, 'section' => $section]);

        return $comment;
    }
}
