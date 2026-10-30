<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Notifications\CommunicationNotification;
use Modules\Communication\Application\Services\CommunicationSettings;
use Modules\Communication\Domain\Enums\AccessRequestStatus;
use Modules\Communication\Domain\Models\CommunicationAccessRequest;

/**
 * Someone other than the requester approves (for a limited number of hours,
 * never more than the system allows or than was asked for) or rejects, with a
 * written note either way.
 */
final class DecideInvestigationAccessAction
{
    public function __construct(
        private readonly CommunicationSettings $settings,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function execute(User $approver, CommunicationAccessRequest $request, bool $approve, string $note, ?int $hours = null): CommunicationAccessRequest
    {
        if (! Gate::forUser($approver)->allows('decide', $request)) {
            throw new AuthorizationException;
        }
        $limit = min($this->settings->breakGlassMaxHours(), max(1, (int) ($request->requested_hours ?? $this->settings->breakGlassMaxHours())));
        $granted = $hours ?? $limit;
        if ($approve && ($granted < 1 || $granted > $limit)) {
            throw ValidationException::withMessages(['hours' => __('communication.errors.investigation_hours', ['max' => $limit])]);
        }

        $request->forceFill([
            'status' => $approve ? AccessRequestStatus::Approved : AccessRequestStatus::Rejected,
            'decided_by' => $approver->getKey(),
            'decided_at' => now(),
            'decision_note' => trim($note),
            'starts_at' => $approve ? now() : null,
            'expires_at' => $approve ? now()->addHours($granted) : null,
        ])->save();

        $this->audit->execute(AuditEventType::CommunicationAccessDecided, $approver, $request, null, null, [
            'status' => $request->status->value, 'hours' => $approve ? $granted : null,
            'expires_at' => $request->expires_at?->toIso8601String(), 'requested_by' => $request->requested_by,
        ], $note);
        $request->requester?->notify(new CommunicationNotification($approve ? 'access_approved' : 'access_rejected',
            ['name' => $approver->name, 'case' => $request->case_reference], '/communication/investigations/'.$request->getKey()));

        return $request;
    }
}
