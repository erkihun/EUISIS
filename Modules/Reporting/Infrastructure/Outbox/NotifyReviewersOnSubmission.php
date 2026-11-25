<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Outbox;

use Modules\Reporting\Application\Ports\NotificationPublisher;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Contracts\Events\ReportSubmittedV1;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;

/**
 * Reporting's own consumer of ReportSubmittedV1: tells the reviewers of the
 * first step that a report awaits them. Idempotent twice over: once per event
 * id (InboxGuard) and once per person (the delivery key).
 */
final readonly class NotifyReviewersOnSubmission
{
    public function __construct(private InboxGuard $inbox, private NotificationPublisher $notifications, private ReportingAccessResolver $access) {}

    public function handle(ReportSubmittedV1 $event): void
    {
        $this->inbox->once('notify_reviewers', $event->eventId, function () use ($event): void {
            $obligation = Obligation::query()->find($event->obligationId);
            $step = ReviewStep::query()->where('submission_id', $event->submissionId)->where('status', 'pending')->first();
            if ($obligation === null || $step === null) {
                return;
            }
            $recipients = $step->reviewer_user_id !== null
                ? [(int) $step->reviewer_user_id]
                : ($obligation->receiving_organization_id === null ? [] : array_map(fn ($user): int => $user->id, $this->access->receiversFor($step->required_permission, $obligation->receiving_organization_id, 10)));
            $cycle = Cycle::query()->find($obligation->cycle_id);
            $type = ReportType::query()->find($obligation->report_type_id);

            $this->notifications->publish('workflow', 'submitted', "submitted:{$event->submissionId}", $recipients, [
                'institution' => (string) $obligation->organization_name_en, 'institution_am' => (string) $obligation->organization_name_am,
                'period' => (string) $cycle?->period_label_en, 'period_am' => (string) $cycle?->period_label_am,
                'type' => (string) $type?->name_en, 'type_am' => (string) $type?->name_am, 'reference' => $event->referenceNo,
            ], $obligation->id);
        });
    }
}
