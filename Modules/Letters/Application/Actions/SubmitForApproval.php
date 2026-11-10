<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LetterNotifier;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Models\Letter;

/** Sends a draft to its signatory. A letter needs a subject, a body and either an addressee in the system or an external party. */
final class SubmitForApproval
{
    public function __construct(
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
        private readonly LetterNotifier $notifier,
    ) {}

    public function execute(Letter $letter, User $actor): Letter
    {
        $errors = [];
        if (trim((string) $letter->subject) === '') {
            $errors['subject'] = __('letters.validation.subject_required');
        }
        if (trim(strip_tags((string) $letter->body)) === '') {
            $errors['body'] = __('letters.validation.body_required');
        }
        if ($letter->direction->value === 'outgoing' && $letter->recipient_organization_id === null && trim((string) $letter->external_party_name) === '') {
            $errors['recipient'] = __('letters.validation.recipient_required');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $letter->forceFill(['status' => LetterStatus::PendingApproval->value, 'updated_by' => $actor->id])->save();
        $this->events->record($letter, LetterEventType::Submitted, $actor, ['signatory_user_id' => $letter->signatory_user_id]);
        $this->audit->record(AuditEventType::LetterSubmitted, $actor, $letter, $letter->organization_id, ['signatory_user_id' => $letter->signatory_user_id]);

        $recipients = $letter->signatory_user_id !== null
            ? collect([$letter->signatory])
            : $this->notifier->usersFor('letters.approve', $letter->organization_id);
        $this->notifier->send($recipients, new LetterNotification(LetterNotification::AWAITING_APPROVAL, $this->notifier->params($letter), ['letter' => $letter->id]));

        return $letter;
    }
}
