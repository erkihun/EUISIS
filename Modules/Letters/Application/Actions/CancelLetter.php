<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Models\Letter;

/** Withdraws a letter that was never dispatched. Dispatched letters are superseded, never cancelled. */
final class CancelLetter
{
    public function __construct(
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
    ) {}

    public function execute(Letter $letter, string $reason, User $actor): Letter
    {
        $letter->forceFill(['status' => LetterStatus::Cancelled->value, 'updated_by' => $actor->id])->save();
        $this->events->record($letter, LetterEventType::Cancelled, $actor, ['reason' => mb_substr($reason, 0, 500)]);
        $this->audit->record(AuditEventType::LetterCancelled, $actor, $letter, $letter->organization_id, ['subject' => $letter->subject], $reason);

        return $letter;
    }
}
