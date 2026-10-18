<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\AnnouncementStatus;
use App\Events\Communication\AnnouncementPublished;
use App\Jobs\Communication\DeliverAnnouncement;
use App\Models\Communication\Announcement;
use App\Models\User;
use App\Services\Communication\CommunicationBroadcaster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Publishes now, or schedules for later. The person who publishes or schedules
 * is recorded as the publisher; a scheduled announcement goes out on its own
 * at its time (communication:publish-scheduled) under that name.
 */
final class PublishAnnouncementAction
{
    public function __construct(
        private readonly CommunicationBroadcaster $broadcaster,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function execute(User $actor, Announcement $announcement, ?Carbon $at = null): Announcement
    {
        if (! Gate::forUser($actor)->allows('publish', $announcement)) {
            throw new AuthorizationException;
        }
        if ($announcement->expires_at !== null && $announcement->expires_at->lessThanOrEqualTo($at ?? now())) {
            throw ValidationException::withMessages(['publish_at' => __('communication.errors.expiry_before_publish')]);
        }

        if ($at !== null && $at->isFuture()) {
            $announcement->forceFill(['status' => AnnouncementStatus::Scheduled, 'publish_at' => $at, 'published_by' => $actor->getKey()])->save();
            $this->audit->execute(AuditEventType::CommunicationAnnouncementPublished, $actor, $announcement, $announcement->organization_id,
                null, ['status' => 'SCHEDULED', 'publish_at' => $at->toIso8601String()]);

            return $announcement;
        }

        return $this->publishNow($announcement, $actor);
    }

    /** Puts it live and starts delivery; used directly by the scheduler. */
    public function publishNow(Announcement $announcement, ?User $actor): Announcement
    {
        $published = DB::transaction(function () use ($announcement, $actor): bool {
            $fresh = Announcement::query()->whereKey($announcement->getKey())->lockForUpdate()->first();
            if ($fresh === null || ! $fresh->status->isEditable()) {
                return false;
            }
            $fresh->forceFill([
                'status' => AnnouncementStatus::Published,
                'published_at' => now(),
                'published_by' => $actor?->getKey() ?? $fresh->published_by ?? $fresh->created_by,
            ])->save();
            $announcement->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
        if (! $published) {
            return $announcement;
        }

        $this->audit->execute(AuditEventType::CommunicationAnnouncementPublished, $actor, $announcement, $announcement->organization_id,
            null, ['status' => 'PUBLISHED', 'priority' => $announcement->priority->value]);
        DeliverAnnouncement::dispatch($announcement->getKey())->afterCommit();
        $this->broadcaster->send(new AnnouncementPublished($announcement->getKey(), $announcement->priority->value));

        return $announcement;
    }
}
