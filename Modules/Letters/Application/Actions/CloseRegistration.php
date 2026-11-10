<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Enums\RoutingStatus;
use Modules\Letters\Domain\Models\LetterRegistration;

/** Files the institution's handling of a letter as done. Every routing step must be completed or returned first. */
final class CloseRegistration
{
    public function __construct(
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
    ) {}

    public function execute(LetterRegistration $registration, ?string $note, User $actor): LetterRegistration
    {
        $open = $registration->routings()->whereIn('status', [RoutingStatus::Pending->value, RoutingStatus::Acknowledged->value])->count();
        if ($open > 0) {
            throw ValidationException::withMessages(['closing_note' => __('letters.validation.open_routings', ['count' => $open])]);
        }

        $registration->forceFill([
            'status' => RegistrationStatus::Closed->value,
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'closing_note' => $note,
        ])->save();
        $this->events->record($registration->letter, LetterEventType::Closed, $actor, ['note' => $note !== null ? mb_substr($note, 0, 500) : null], $registration);
        $this->audit->record(AuditEventType::LetterClosed, $actor, $registration->letter, $registration->organization_id, [], $note);

        return $registration;
    }
}
