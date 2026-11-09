<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ReminderService;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\ClarificationStatus;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\ClarificationMessage;

/**
 * A message in a clarification thread. A support officer's reply marks it
 * answered and notifies the institution; a follow-up from the institution
 * opens it again.
 */
final readonly class ReplyClarification
{
    public function __construct(private ReminderService $notices, private ServiceMonitoringAudit $audit) {}

    public function execute(Clarification $clarification, string $body, User $actor, bool $asSupport): ClarificationMessage
    {
        if ($clarification->status === ClarificationStatus::Closed) {
            throw ValidationException::withMessages(['body' => __('service-monitoring.validation.clarification_closed')]);
        }

        $message = DB::transaction(function () use ($clarification, $body, $actor, $asSupport): ClarificationMessage {
            $message = ClarificationMessage::query()->create(['clarification_id' => $clarification->id, 'author_id' => $actor->id, 'body' => $body]);
            $clarification->forceFill(['status' => $asSupport ? ClarificationStatus::Answered : ClarificationStatus::Open])->save();
            $this->audit->record(AuditEventType::ServiceMonitoringClarificationReplied, $actor, $clarification, $clarification->participant?->organization_id, ['as_support' => $asSupport]);

            return $message;
        });
        if ($asSupport) {
            $this->notices->notifyClarificationReplied($clarification);
        }

        return $message;
    }
}
