<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\AnnouncementStatus;
use App\Models\Communication\Announcement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Withdraws a scheduled or published announcement, with a reason. It leaves
 * every reader's feed at once; receipts and acknowledgements are kept.
 */
final class CancelAnnouncementAction
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    public function execute(User $actor, Announcement $announcement, string $reason): Announcement
    {
        if (! Gate::forUser($actor)->allows('cancel', $announcement)) {
            throw new AuthorizationException;
        }
        $before = $announcement->status->value;
        $announcement->forceFill([
            'status' => AnnouncementStatus::Cancelled,
            'cancelled_by' => $actor->getKey(),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();

        $this->audit->execute(AuditEventType::CommunicationAnnouncementCancelled, $actor, $announcement, $announcement->organization_id,
            ['status' => $before], ['status' => 'CANCELLED'], $reason);

        return $announcement;
    }
}
