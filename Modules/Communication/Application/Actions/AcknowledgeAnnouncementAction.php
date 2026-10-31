<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Modules\Communication\Application\Events\AnnouncementAcknowledged;
use Modules\Communication\Application\Services\CommunicationBroadcaster;
use Modules\Communication\Application\Services\CommunicationInbox;
use Modules\Communication\Domain\Models\Announcement;
use Modules\Communication\Domain\Models\AnnouncementReceipt;

/**
 * Reader receipts. One row per reader and announcement, created the first
 * time they see it (no row per recipient at publication): delivered when the
 * feed lists it, viewed when opened, acknowledged when they confirm. Times
 * only move forward and acknowledging twice changes nothing.
 */
final class AcknowledgeAnnouncementAction
{
    public function __construct(
        private readonly CommunicationInbox $inbox,
        private readonly CommunicationBroadcaster $broadcaster,
    ) {}

    public function execute(User $user, Announcement $announcement): AnnouncementReceipt
    {
        if (! Gate::forUser($user)->allows('acknowledge', $announcement)) {
            throw new AuthorizationException;
        }
        $receipt = self::touch($user, $announcement, ['delivered_at', 'viewed_at', 'acknowledged_at']);
        $this->inbox->forgetTotals($user->getKey());
        $this->broadcaster->send(new AnnouncementAcknowledged($announcement->getKey(), (int) $user->getKey()));

        return $receipt;
    }

    /**
     * Sets the given receipt times that are still empty.
     *
     * @param  list<'delivered_at'|'viewed_at'|'acknowledged_at'>  $fields
     */
    public static function touch(User $user, Announcement $announcement, array $fields): AnnouncementReceipt
    {
        $find = fn () => AnnouncementReceipt::query()->where('announcement_id', $announcement->getKey())->where('user_id', $user->getKey())->first();
        $receipt = $find();
        if ($receipt === null) {
            try {
                $receipt = (new AnnouncementReceipt)->forceFill(['announcement_id' => $announcement->getKey(), 'user_id' => $user->getKey()]);
                $receipt->save();
            } catch (UniqueConstraintViolationException) {
                $receipt = $find();
            }
        }
        $changes = [];
        foreach ($fields as $field) {
            if ($receipt->{$field} === null) {
                $changes[$field] = now();
            }
        }
        if ($changes !== []) {
            $receipt->forceFill($changes)->save();
        }

        return $receipt;
    }
}
