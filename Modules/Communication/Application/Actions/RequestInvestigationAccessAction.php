<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Notifications\CommunicationNotification;
use Modules\Communication\Domain\Enums\AccessRequestStatus;
use Modules\Communication\Domain\Enums\AccessScopeType;
use Modules\Communication\Domain\Models\CommunicationAccessRequest;
use Modules\Communication\Domain\Models\Conversation;

/**
 * An investigator asks to read private conversations: one conversation, or
 * the conversations of one employee, with a written reason and a case
 * reference. Nothing is readable until someone else with approval rights
 * approves it, for a limited time.
 */
final class RequestInvestigationAccessAction
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param array{scope_type: string, conversation_id?: ?string, target_user_id?: ?int, reason: string, case_reference: string, hours: int} $data */
    public function execute(User $investigator, array $data): CommunicationAccessRequest
    {
        if (! Gate::forUser($investigator)->allows('create', CommunicationAccessRequest::class)) {
            throw new AuthorizationException;
        }
        $scope = AccessScopeType::from($data['scope_type']);
        $conversationId = $scope === AccessScopeType::Conversation ? ($data['conversation_id'] ?? null) : null;
        $targetId = $scope === AccessScopeType::Employee ? (int) ($data['target_user_id'] ?? 0) : null;
        if ($scope === AccessScopeType::Conversation && ($conversationId === null || ! Conversation::query()->whereKey($conversationId)->exists())) {
            throw ValidationException::withMessages(['conversation_id' => __('communication.errors.investigation_target_invalid')]);
        }
        if ($scope === AccessScopeType::Employee && ($targetId === 0 || ! User::query()->whereKey($targetId)->exists())) {
            throw ValidationException::withMessages(['target_user_id' => __('communication.errors.investigation_target_invalid')]);
        }
        if ($targetId === (int) $investigator->getKey()) {
            throw ValidationException::withMessages(['target_user_id' => __('communication.errors.investigation_target_invalid')]);
        }

        $request = (new CommunicationAccessRequest)->forceFill([
            'requested_by' => $investigator->getKey(),
            'reason' => trim($data['reason']),
            'case_reference' => trim($data['case_reference']),
            'scope_type' => $scope,
            'conversation_id' => $conversationId,
            'target_user_id' => $targetId,
            'status' => AccessRequestStatus::Requested,
            // The hours asked for; the approver may grant fewer (DecideInvestigationAccessAction).
            'requested_hours' => (int) $data['hours'],
        ]);
        $request->save();

        $this->audit->execute(AuditEventType::CommunicationAccessRequested, $investigator, $request, null, null, [
            'scope_type' => $scope->value, 'conversation_id' => $conversationId, 'target_user_id' => $targetId,
            'case_reference' => $request->case_reference, 'hours' => (int) $data['hours'],
        ], $request->reason);

        $approvers = User::query()->permission('communication.break_glass.approve')->where('status', 'active')
            ->whereKeyNot($investigator->getKey())->get();
        Notification::send($approvers, new CommunicationNotification('access_requested',
            ['name' => $investigator->name, 'case' => $request->case_reference], '/communication/investigations/'.$request->getKey()));

        return $request;
    }
}
