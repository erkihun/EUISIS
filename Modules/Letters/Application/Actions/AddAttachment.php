<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Modules\Letters\Application\Services\LetterAttachmentStore;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\AttachmentKind;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterAttachment;

/** Adds a scanned original or an annex to a letter. */
final class AddAttachment
{
    public function __construct(
        private readonly LetterAttachmentStore $store,
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
    ) {}

    public function execute(Letter $letter, UploadedFile $file, AttachmentKind $kind, User $actor): LetterAttachment
    {
        $attachment = $this->store->store($file, $letter, $kind, $actor);
        $this->events->record($letter, LetterEventType::AttachmentAdded, $actor, ['kind' => $kind->value, 'name' => $attachment->original_name]);
        $this->audit->record(AuditEventType::LetterAttachmentAdded, $actor, $attachment, $letter->organization_id, ['letter_id' => $letter->id, 'kind' => $kind->value, 'sha256' => $attachment->sha256]);

        return $attachment;
    }
}
