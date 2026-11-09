<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Services\AttachmentStorage;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\MessageAttachment;

/**
 * Stores a file for a message the person is about to send in this
 * conversation. It stays unattached (message_id null) and visible only to its
 * uploader until the message is sent.
 */
final class UploadAttachmentAction
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    public function execute(Conversation $conversation, User $user, UploadedFile $file): MessageAttachment
    {
        Gate::forUser($user)->authorize('sendMessage', $conversation);
        abort_unless($user->can('attachments.upload'), 403);

        try {
            $stored = $this->storage->store($file, 'communication/'.$conversation->getKey());
        } catch (ValidationException $e) {
            Cache::increment('communication:metrics:attachment_rejections');
            throw $e;
        }

        $attachment = (new MessageAttachment)->forceFill([...$stored, 'conversation_id' => $conversation->getKey(), 'uploaded_by' => $user->getKey()]);
        $attachment->save();

        return $attachment;
    }
}
