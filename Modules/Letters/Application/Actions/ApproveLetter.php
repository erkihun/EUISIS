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

/** The signatory signs: the letter is approved and may be dispatched. */
final class ApproveLetter
{
    public function __construct(
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
        private readonly LetterNotifier $notifier,
    ) {}

    public function execute(Letter $letter, User $actor): Letter
    {
        $letter->forceFill([
            'status' => LetterStatus::Approved->value,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'signatory_user_id' => $letter->signatory_user_id ?? $actor->id,
            'updated_by' => $actor->id,
        ])->save();
        $this->events->record($letter, LetterEventType::Approved, $actor);
        $this->audit->record(AuditEventType::LetterApproved, $actor, $letter, $letter->organization_id, ['subject' => $letter->subject]);
        if ($letter->created_by !== null && $letter->created_by !== $actor->id) {
            $this->notifier->send([$letter->creator], new LetterNotification(LetterNotification::APPROVED, $this->notifier->params($letter), ['letter' => $letter->id]));
        }

        return $letter;
    }
}
