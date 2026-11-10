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
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\LetterPriority;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Models\LetterRegistration;

/** The receiving institution's registrar receives a letter dispatched to it: its own incoming number is drawn. */
final class ReceiveLetter
{
    public function __construct(
        private readonly LetterNumbering $numbering,
        private readonly LetterStandards $standards,
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
    ) {}

    /** @param array{received_on?: string|null, due_on?: string|null, physical_location?: string|null} $data */
    public function execute(LetterRegistration $registration, array $data, User $actor): LetterRegistration
    {
        return DB::transaction(function () use ($registration, $data, $actor): LetterRegistration {
            $registration->forceFill([
                'status' => RegistrationStatus::Registered->value,
                'registered_at' => now(),
                'registered_by' => $actor->id,
                'received_on' => $data['received_on'] ?? now()->toDateString(),
                'due_on' => $data['due_on'] ?? $this->standards->dueDate($registration->organization_id, $registration->letter?->priority ?? LetterPriority::Normal)->toDateString(),
                'physical_location' => isset($data['physical_location']) && trim((string) $data['physical_location']) !== '' ? mb_substr(trim((string) $data['physical_location']), 0, 120) : null,
            ])->save();
            $this->numbering->assign($registration);

            $letter = $registration->letter;
            $this->events->record($letter, LetterEventType::Received, $actor, ['organization_id' => $registration->organization_id], $registration);
            $this->events->record($letter, LetterEventType::NumberAssigned, $actor, ['number' => $registration->registry_number], $registration);
            $this->audit->record(AuditEventType::LetterReceived, $actor, $letter, $registration->organization_id, ['number' => $registration->registry_number, 'subject' => $letter->subject]);

            return $registration->fresh();
        });
    }
}
