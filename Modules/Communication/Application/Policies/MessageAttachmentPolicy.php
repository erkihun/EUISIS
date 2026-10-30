<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Policies;

use App\Models\User;
use Modules\Communication\Application\Services\CommunicationAccess;
use Modules\Communication\Domain\Models\MessageAttachment;

/**
 * Files open to current members of their conversation only, once sent (or to
 * their uploader before), and only when the scan state allows it.
 */
class MessageAttachmentPolicy
{
    public function __construct(private readonly CommunicationAccess $access) {}

    public function download(User $user, MessageAttachment $attachment): bool
    {
        if (! $user->can('attachments.download') || ! $attachment->scan_status->isDownloadable()) {
            return false;
        }
        if ($attachment->message_id === null) {
            return $attachment->uploaded_by === $user->getKey() && $this->access->canRead($user, $attachment->conversation);
        }
        $message = $attachment->message()->withTrashed()->first();

        return $message !== null && ! $message->trashed() && $this->access->canRead($user, $attachment->conversation);
    }

    /** An unsent upload can be discarded by its uploader. */
    public function delete(User $user, MessageAttachment $attachment): bool
    {
        return $attachment->message_id === null && $attachment->uploaded_by === $user->getKey();
    }
}
