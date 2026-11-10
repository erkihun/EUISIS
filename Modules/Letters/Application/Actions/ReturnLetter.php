<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LetterNotifier;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Models\Letter;

/** The signatory sends a draft back with a reason; the author may edit and resubmit. */
final class ReturnLetter
{
    public function __construct(
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
        private readonly LetterNotifier $notifier,
    ) {}

    public function execute(Letter $letter, string $reason, User $actor): Letter
    {
        $letter->forceFill(['status' => LetterStatus::Draft->value, 'updated_by' => $actor->id])->save();
        $this->events->record($letter, LetterEventType::Returned, $actor, ['reason' => $reason]);
        $this->audit->record(AuditEventType::LetterReturned, $actor, $letter, $letter->organization_id, ['subject' => $letter->subject], $reason);
        if ($letter->created_by !== null) {
            $this->notifier->send([$letter->creator], new LetterNotification(LetterNotification::RETURNED, [...$this->notifier->params($letter), 'reason' => mb_substr($reason, 0, 200)], ['letter' => $letter->id]));
        }

        return $letter;
    }
}
