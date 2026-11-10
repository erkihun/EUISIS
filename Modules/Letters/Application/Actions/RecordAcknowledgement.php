<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Models\LetterRegistration;

/** The dispatch register's proof of delivery: when an external recipient acknowledged receipt, and how (signed copy, postal receipt, courier confirmation). */
final class RecordAcknowledgement
{
    public function __construct(
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
    ) {}

    /** @param array{acknowledged_on?: string|null, acknowledgement_note?: string|null} $data */
    public function execute(LetterRegistration $registration, array $data, User $actor): LetterRegistration
    {
        $on = isset($data['acknowledged_on']) && $data['acknowledged_on'] !== null && $data['acknowledged_on'] !== '' ? now()->parse((string) $data['acknowledged_on']) : now();
        $note = isset($data['acknowledgement_note']) && trim((string) $data['acknowledgement_note']) !== '' ? mb_substr(trim((string) $data['acknowledgement_note']), 0, 500) : null;
        $registration->forceFill(['acknowledged_at' => $on, 'acknowledgement_note' => $note])->save();

        $letter = $registration->letter;
        $this->events->record($letter, LetterEventType::ReceiptAcknowledged, $actor, ['on' => $on->toDateString(), 'note' => $note], $registration);
        $this->audit->record(AuditEventType::LetterReceiptAcknowledged, $actor, $letter, $registration->organization_id, ['number' => $registration->registry_number, 'on' => $on->toDateString()], $note);

        return $registration;
    }
}
