<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LetterNumbering;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Application\Services\LetterStandards;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterRegistration;

/**
 * Registers a letter that arrived from outside the system (a court, a federal
 * office, a citizen) or from an institution that is not using the module: the
 * receiving institution's own number is drawn at once.
 */
final class RegisterIncomingLetter
{
    public function __construct(
        private readonly LetterNumbering $numbering,
        private readonly LetterStandards $standards,
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
    ) {}

    /** @param array<string, mixed> $data validated RegisterIncomingRequest data */
    public function execute(array $data, User $actor): Letter
    {
        return DB::transaction(function () use ($data, $actor): Letter {
            $letter = Letter::query()->create([
                'direction' => LetterDirection::Incoming->value,
                'status' => LetterStatus::Received->value,
                'organization_id' => $data['organization_id'],
                'unit_id' => $data['unit_id'] ?? null,
                'sender_organization_id' => $data['sender_organization_id'] ?? null,
                'external_party_name' => $data['external_party_name'] ?? null,
                'external_party_address' => $data['external_party_address'] ?? null,
                'external_reference' => $data['external_reference'] ?? null,
                'subject' => $data['subject'],
                'summary' => $data['summary'] ?? null,
                'language' => $data['language'] ?? 'am',
                'priority' => $data['priority'] ?? 'normal',
                'classification' => $data['classification'] ?? 'internal',
                'file_code' => isset($data['file_code']) && trim((string) $data['file_code']) !== '' ? mb_substr(trim((string) $data['file_code']), 0, 60) : null,
                'file_class_id' => $data['file_class_id'] ?? null,
                'letter_date' => $data['letter_date'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $registration = LetterRegistration::query()->create([
                'letter_id' => $letter->id,
                'organization_id' => $letter->organization_id,
                'direction' => LetterDirection::Incoming->value,
                'status' => RegistrationStatus::Registered->value,
                'registered_at' => now(),
                'registered_by' => $actor->id,
                'received_on' => $data['received_on'] ?? now()->toDateString(),
                // The institution's reply-time standard sets the date when the registrar leaves it empty.
                'due_on' => $data['due_on'] ?? $this->standards->dueDate($letter->organization_id, $letter->priority)->toDateString(),
                'physical_location' => isset($data['physical_location']) && trim((string) $data['physical_location']) !== '' ? mb_substr(trim((string) $data['physical_location']), 0, 120) : null,
            ]);
            $registration->setRelation('letter', $letter);
            $this->numbering->assign($registration);

            $this->events->record($letter, LetterEventType::Registered, $actor, ['direction' => 'incoming'], $registration);
            $this->events->record($letter, LetterEventType::NumberAssigned, $actor, ['number' => $registration->registry_number], $registration);
            $this->audit->record(AuditEventType::LetterRegistered, $actor, $letter, $letter->organization_id, [
                'direction' => 'incoming', 'number' => $registration->registry_number, 'subject' => $letter->subject, 'classification' => $letter->classification->value,
            ]);

            return $letter->fresh(['registrations']);
        });
    }
}
