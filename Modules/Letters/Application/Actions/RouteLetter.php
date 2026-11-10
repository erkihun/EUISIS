<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LetterNotifier;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Enums\RoutingStatus;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;

/**
 * Forwards a registered letter inside the institution to a unit or a person
 * with an instruction (ትእዛዝ) and a due date. The addressee is always checked
 * against the registry entry's institution, never trusted from the request.
 */
final class RouteLetter
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
        private readonly LetterNotifier $notifier,
    ) {}

    /** @param array{to_user_id?: int|string|null, to_unit_id?: string|null, instruction: string, due_on?: string|null} $data */
    public function execute(LetterRegistration $registration, array $data, User $actor): LetterRouting
    {
        $toUser = isset($data['to_user_id']) && $data['to_user_id'] !== null && $data['to_user_id'] !== '' ? User::query()->find((int) $data['to_user_id']) : null;
        $toUnit = isset($data['to_unit_id']) && $data['to_unit_id'] !== null && $data['to_unit_id'] !== '' ? OrganizationUnit::query()->find($data['to_unit_id']) : null;

        if ($toUser === null && $toUnit === null) {
            throw ValidationException::withMessages(['to_user_id' => __('letters.validation.addressee_required')]);
        }
        if ($toUser !== null && ($toUser->status !== 'active' || ! $this->scope->canAccessOrganization($toUser, $registration->organization_id))) {
            throw ValidationException::withMessages(['to_user_id' => __('letters.validation.addressee_outside_institution')]);
        }
        if ($toUnit !== null && $toUnit->organization_id !== $registration->organization_id) {
            throw ValidationException::withMessages(['to_unit_id' => __('letters.validation.unit_outside_institution')]);
        }

        return DB::transaction(function () use ($registration, $data, $actor, $toUser, $toUnit): LetterRouting {
            $step = (int) LetterRouting::query()->where('registration_id', $registration->id)->max('step') + 1;
            $routing = LetterRouting::query()->create([
                'registration_id' => $registration->id,
                'letter_id' => $registration->letter_id,
                'step' => $step,
                'from_user_id' => $actor->id,
                'to_user_id' => $toUser?->id,
                'to_unit_id' => $toUnit?->id,
                'instruction' => $data['instruction'],
                'due_on' => $data['due_on'] ?? null,
                'status' => RoutingStatus::Pending->value,
            ]);
            if ($registration->status === RegistrationStatus::Registered) {
                $registration->forceFill(['status' => RegistrationStatus::Routed->value])->save();
            }

            $letter = $registration->letter;
            $this->events->record($letter, LetterEventType::Routed, $actor, ['step' => $step, 'to_user_id' => $toUser?->id, 'to_unit_id' => $toUnit?->id, 'due_on' => $data['due_on'] ?? null], $registration);
            $this->audit->record(AuditEventType::LetterRouted, $actor, $letter, $registration->organization_id, ['step' => $step, 'to_user_id' => $toUser?->id, 'to_unit_id' => $toUnit?->id]);
            if ($toUser !== null) {
                $this->notifier->send([$toUser], new LetterNotification(LetterNotification::ROUTED, [...$this->notifier->params($letter), 'instruction' => mb_substr((string) $data['instruction'], 0, 160)], ['letter' => $letter->id]));
            }

            return $routing;
        });
    }
}
