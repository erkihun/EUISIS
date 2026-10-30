<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Modules\Communication\Domain\Enums\AccessRequestStatus;
use Modules\Communication\Domain\Models\CommunicationAccessRequest;

/** Ends an approved investigation early: an approver may, and so may the investigator once done. */
final class RevokeInvestigationAccessAction
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    public function execute(User $actor, CommunicationAccessRequest $request, ?string $reason = null): CommunicationAccessRequest
    {
        if (! Gate::forUser($actor)->allows('revoke', $request)) {
            throw new AuthorizationException;
        }
        $request->forceFill(['status' => AccessRequestStatus::Revoked, 'revoked_by' => $actor->getKey(), 'revoked_at' => now()])->save();
        $this->audit->execute(AuditEventType::CommunicationAccessRevoked, $actor, $request, null, null, ['status' => 'REVOKED'], $reason);

        return $request;
    }
}
