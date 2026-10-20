<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\ReportStatus;
use App\Models\Communication\CommunicationReport;
use App\Models\Communication\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A moderator closes a report: dismissed, or actioned, optionally removing the
 * reported message (shown to members as "This message was deleted"). A note
 * is always recorded. Moderators never review their own reports or reports
 * about themselves.
 */
final class ResolveReportAction
{
    public function __construct(
        private readonly DeleteMessageAction $delete,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function execute(User $moderator, CommunicationReport $report, ReportStatus $outcome, string $note, bool $removeMessage = false): CommunicationReport
    {
        if (! Gate::forUser($moderator)->allows('review', $report) || (int) $report->reported_user_id === (int) $moderator->getKey()) {
            throw new AuthorizationException;
        }
        if (! in_array($outcome, [ReportStatus::Actioned, ReportStatus::Dismissed], true) || in_array($report->status, [ReportStatus::Actioned, ReportStatus::Dismissed], true)) {
            throw ValidationException::withMessages(['status' => __('communication.errors.report_closed')]);
        }

        $removed = false;
        if ($removeMessage && $outcome === ReportStatus::Actioned && $report->message_id !== null) {
            $message = Message::query()->find($report->message_id);
            if ($message !== null) {
                $this->delete->execute($message, $moderator, authorized: true, reason: 'report:'.$report->getKey());
                $removed = true;
            }
        }

        $report->forceFill([
            'status' => $outcome,
            'reviewed_by' => $moderator->getKey(),
            'reviewed_at' => now(),
            'resolution_note' => trim($note),
        ])->save();

        $this->audit->execute(AuditEventType::CommunicationReportReviewed, $moderator, $report, $report->conversation?->organization_id,
            null, ['status' => $outcome->value, 'message_removed' => $removed], $note);

        return $report;
    }
}
