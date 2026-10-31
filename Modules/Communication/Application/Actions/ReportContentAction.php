<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Modules\Communication\Application\Services\CommunicationAccess;
use Modules\Communication\Domain\Enums\ConversationType;
use Modules\Communication\Domain\Enums\ReportCategory;
use Modules\Communication\Domain\Enums\ReportStatus;
use Modules\Communication\Domain\Enums\ReportSubject;
use Modules\Communication\Domain\Models\CommunicationReport;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\Message;

/**
 * A member reports a message (or a whole group or channel) to the moderators.
 * Reporting the same thing again while a report is still open returns that
 * report instead of opening a second one. The audit row holds ids only.
 */
final class ReportContentAction
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function message(User $reporter, Message $message, ReportCategory $category, ?string $details): CommunicationReport
    {
        if (! Gate::forUser($reporter)->allows('report', $message)) {
            throw new AuthorizationException;
        }

        return $this->file($reporter, ReportSubject::Message, $message->conversation, $message, $message->sender_id, $category, $details);
    }

    public function conversation(User $reporter, Conversation $conversation, ReportCategory $category, ?string $details): CommunicationReport
    {
        if ($conversation->type === ConversationType::Direct || ! $reporter->can('communication.reports.create') || ! $this->access->canRead($reporter, $conversation)) {
            throw new AuthorizationException;
        }

        return $this->file($reporter, ReportSubject::Conversation, $conversation, null, $conversation->created_by, $category, $details);
    }

    private function file(User $reporter, ReportSubject $subject, Conversation $conversation, ?Message $message, ?int $reportedUserId, ReportCategory $category, ?string $details): CommunicationReport
    {
        $open = CommunicationReport::query()->where('reporter_id', $reporter->getKey())->where('subject_type', $subject->value)
            ->where('conversation_id', $conversation->getKey())->where('message_id', $message?->getKey())
            ->whereIn('status', [ReportStatus::Open->value, ReportStatus::UnderReview->value])->first();
        if ($open !== null) {
            return $open;
        }

        $report = (new CommunicationReport)->forceFill([
            'reporter_id' => $reporter->getKey(),
            'subject_type' => $subject,
            'message_id' => $message?->getKey(),
            'conversation_id' => $conversation->getKey(),
            'reported_user_id' => $reportedUserId,
            'category' => $category,
            'details' => filled($details) ? trim((string) $details) : null,
            'status' => ReportStatus::Open,
        ]);
        $report->save();

        $this->audit->execute(AuditEventType::CommunicationReportCreated, $reporter, $report, $conversation->organization_id, null,
            ['subject' => $subject->value, 'category' => $category->value, 'message_id' => $message?->getKey(), 'conversation_id' => $conversation->getKey()]);

        return $report;
    }
}
