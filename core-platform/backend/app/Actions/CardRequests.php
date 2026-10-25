<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CodeGenerator;
use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The ID card request flow, as in EUISIS (Submit/Approve/Reject/Cancel
 * CardRequestAction and ReplaceCardAction):
 *  - one pending request per active employee;
 *  - approval by someone other than the requester, for a placed active
 *    employee who has no live card (a replacement retires the old card first);
 *    it creates the card waiting to print, with its number, public reference and
 *    expiry from Core settings, and links the replacement record;
 *  - a lost, damaged, revoked, expired or suspended card is replaced: it is
 *    retired and a replacement request opens for the same employee.
 * The employee row is locked first in every step, so two requests or two
 * approvals for one employee cannot both pass.
 */
final readonly class CardRequests
{
    public function __construct(
        private AuditWriter $audit,
        private OrganizationScope $scope,
        private CodeGenerator $codes,
        private CoreSettings $settings,
    ) {}

    public function submit(Employee $employee, string $type, ?string $reason, User $actor): CardRequest
    {
        if (! in_array($type, ['new', 'renewal', 'correction'], true)) {
            throw ValidationException::withMessages(['request_type' => 'Use replace on the old card for a replacement, lost or damaged card.']);
        }

        return DB::transaction(function () use ($employee, $type, $reason, $actor): CardRequest {
            $employee = $this->lockEmployee($employee, $actor);
            $problems = $this->eligibility($employee, $type);
            if ($problems !== []) {
                throw ValidationException::withMessages(['employee' => array_values($problems)]);
            }
            $previous = in_array($type, ['renewal', 'correction'], true)
                ? IdCard::query()->where('employee_id', $employee->id)->where('is_current', true)->latest('created_at')->first()
                : null;

            return $this->open($employee, $type, $reason, $previous, $actor);
        });
    }

    /**
     * Why the employee cannot have a card request of this type now, as reason
     * code => message; empty when eligible. The same rules hold at approval.
     *
     * @return array<string, string>
     */
    public function eligibility(Employee $employee, string $type = 'new', bool $forApproval = false): array
    {
        $employee->loadMissing('currentAssignment.organization', 'currentAssignment.position');
        $assignment = $employee->currentAssignment;
        $current = IdCard::query()->where('employee_id', $employee->id)->where('is_current', true)->latest('created_at')->first();
        $live = IdCard::query()->where('employee_id', $employee->id)->whereIn('status', IdCard::LIVE)->exists();

        return array_filter([
            'EMPLOYEE_NOT_APPROVED' => ! $employee->isApproved() ? 'The employee has not been approved yet: a card follows verification and approval.' : null,
            'EMPLOYEE_INACTIVE' => $employee->isApproved() && $employee->status !== Employee::ACTIVE ? 'Cards are requested only for active employees.' : null,
            'NO_EFFECTIVE_ASSIGNMENT' => $assignment === null || (string) $assignment->employee_id !== (string) $employee->id || ! $assignment->isEffective()
                ? 'The employee has no placement in force.' : null,
            'ORGANIZATION_INACTIVE' => $assignment !== null && $assignment->organization?->status !== Organization::ACTIVE
                ? 'The employee\'s organization is not in service.' : null,
            'POSITION_INACTIVE' => $assignment !== null && ($assignment->position === null || ! $assignment->position->is_active)
                ? 'The employee\'s position is retired.' : null,
            'IDENTITY_INCOMPLETE' => trim((string) $employee->first_name) === '' || trim((string) $employee->last_name) === ''
                ? 'The employee\'s first and last names are required on a card.' : null,
            'PHOTO_MISSING' => $this->settings->get('id_cards.require_photo') && $employee->photo_path === null
                ? 'The employee has no photo for the card.' : null,
            'PENDING_REQUEST' => ! $forApproval && CardRequest::query()->where('employee_id', $employee->id)->whereIn('status', CardRequest::PENDING)->exists()
                ? 'This employee already has a card request waiting for a decision.' : null,
            'LIVE_CARD_EXISTS' => $type === 'new' && $live
                ? 'The employee already has a live card; request a correction, or replace it when lost or damaged.' : null,
            'NO_CURRENT_CARD' => in_array($type, ['renewal', 'correction'], true) && $current === null
                ? 'Renewal and correction require an existing current card.' : null,
        ]);
    }

    public function replace(IdCard $old, ?string $reason, User $actor): CardRequest
    {
        return DB::transaction(function () use ($old, $reason, $actor): CardRequest {
            $employee = $this->lockEmployee($old->employee()->firstOrFail(), $actor);
            $old = IdCard::query()->lockForUpdate()->findOrFail($old->id);
            if (! in_array($old->status, IdCard::REPLACEABLE, true)) {
                throw ValidationException::withMessages(['card' => 'Only a lost, damaged, revoked, expired or suspended card can be replaced.']);
            }
            $this->assertNoPendingRequest($employee);

            $before = $old->status;
            $old->update(['status' => IdCard::REPLACED, 'is_current' => false]);
            $type = match ($before) {
                IdCard::LOST => 'lost',
                IdCard::DAMAGED => 'damaged',
                default => 'replacement',
            };
            $request = $this->open($employee, $type, $reason ?? 'Card replacement for '.$before.' card', $old, $actor);
            // The new card is named here when the request is approved (the column may not be empty).
            DB::table('card_replacements')->insert([
                'id' => (string) Str::uuid(),
                'old_card_id' => $old->id,
                'new_card_id' => $old->id,
                'reason' => mb_substr($reason ?? $before, 0, 255),
                'replaced_by' => $actor->getKey(),
                'replaced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->write('card.replaced', $actor, $old, $this->organizationId($employee), ['status' => $before], ['status' => IdCard::REPLACED, 'request_id' => $request->id], $reason);

            return $request;
        });
    }

    public function approve(CardRequest $request, User $actor, ?string $notes = null): IdCard
    {
        return DB::transaction(function () use ($request, $actor, $notes): IdCard {
            $employee = $this->lockEmployee($request->employee()->firstOrFail(), $actor);
            $request = CardRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertPending($request);
            if ((int) $request->requested_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['request' => 'A card request must be approved by someone other than the person who submitted it.']);
            }
            $problems = array_diff_key($this->eligibility($employee, 'replacement', forApproval: true), ['LIVE_CARD_EXISTS' => true, 'NO_CURRENT_CARD' => true]);
            if ($problems !== []) {
                throw ValidationException::withMessages(['request' => array_values($problems)]);
            }
            if (in_array($request->request_type, ['correction', 'renewal'], true)) {
                $previous = IdCard::query()->where('employee_id', $employee->id)
                    ->lockForUpdate()->find($request->previous_card_id);
                if ($previous === null || ! $previous->is_current) {
                    throw ValidationException::withMessages(['request' => 'The previous card is no longer current.']);
                }
                if ($request->request_type === 'renewal' && $previous->status !== IdCard::EXPIRED
                    && ($previous->expires_at === null || $previous->expires_at->isFuture())) {
                    throw ValidationException::withMessages(['request' => 'Only an expired card can be renewed.']);
                }
                $previous->update(['status' => IdCard::REPLACED, 'is_current' => false]);
            }
            if (IdCard::query()->where('employee_id', $employee->id)->whereIn('status', IdCard::LIVE)->exists()) {
                throw ValidationException::withMessages(['request' => 'The employee already has a live card; replace it instead.']);
            }

            $request->update(['status' => CardRequest::APPROVED, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'notes' => $notes]);
            $card = IdCard::query()->create([
                'employee_id' => $employee->id,
                'card_request_id' => $request->id,
                'previous_card_id' => $request->previous_card_id,
                'card_number' => $this->codes->code('card'),
                'status' => IdCard::PENDING_PRINT,
                'is_current' => true,
                'expires_at' => now()->addYears($this->settings->int('id_cards.validity_years')),
                'public_card_uuid' => (string) Str::uuid(),
                'qr_status' => 'active',
                'token_version' => 1,
                'display_snapshot' => [
                    'full_name' => $employee->full_name,
                    'employee_number' => $employee->employee_number,
                    'organization' => $employee->currentAssignment->organization?->name_en,
                ],
            ]);
            if ($request->previous_card_id !== null) {
                DB::table('card_replacements')->where('old_card_id', $request->previous_card_id)->whereColumn('new_card_id', 'old_card_id')
                    ->update(['new_card_id' => $card->id, 'updated_at' => now()]);
                if (in_array($request->request_type, ['correction', 'renewal'], true)) {
                    DB::table('card_replacements')->insert([
                        'id' => (string) Str::uuid(),
                        'old_card_id' => $request->previous_card_id,
                        'new_card_id' => $card->id,
                        'reason' => mb_substr($request->request_reason ?? $request->request_type, 0, 255),
                        'replaced_by' => $actor->getKey(),
                        'replaced_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            $this->audit->write('card_request.approved', $actor, $request, $this->organizationId($employee), null,
                ['card_id' => $card->id, 'card_number' => $card->card_number, 'status' => IdCard::PENDING_PRINT], $notes);

            return $card;
        });
    }

    public function close(CardRequest $request, string $decision, string $reason, User $actor): CardRequest
    {
        return DB::transaction(function () use ($request, $decision, $reason, $actor): CardRequest {
            $employee = $this->lockEmployee($request->employee()->firstOrFail(), $actor);
            $request = CardRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertPending($request);
            $status = $decision === 'reject' ? CardRequest::REJECTED : CardRequest::CANCELLED;
            $request->update($decision === 'reject'
                ? ['status' => $status, 'rejected_by' => $actor->getKey(), 'rejected_at' => now(), 'rejection_reason' => $reason]
                : ['status' => $status, 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit->write('card_request.'.($decision === 'reject' ? 'rejected' : 'cancelled'), $actor, $request, $this->organizationId($employee), null, ['status' => $status], $reason);

            return $request;
        });
    }

    private function open(Employee $employee, string $type, ?string $reason, ?IdCard $previous, User $actor): CardRequest
    {
        $request = CardRequest::query()->create([
            'employee_id' => $employee->id,
            'requested_by' => $actor->getKey(),
            'request_type' => $type,
            'status' => CardRequest::SUBMITTED,
            'request_reason' => $reason,
            'previous_card_id' => $previous?->id,
            'submitted_at' => now(),
        ]);
        $this->audit->write('card_request.submitted', $actor, $request, $this->organizationId($employee), null, ['status' => CardRequest::SUBMITTED, 'request_type' => $type], $reason);

        return $request;
    }

    private function lockEmployee(Employee $employee, User $actor): Employee
    {
        $employee = Employee::query()->with('currentAssignment.organization')->lockForUpdate()->findOrFail($employee->id);
        if (! $this->scope->allows($actor, $employee->currentAssignment?->organization_id)) {
            throw ValidationException::withMessages(['employee' => 'This employee is outside your organizations.']);
        }

        return $employee;
    }

    private function assertNoPendingRequest(Employee $employee): void
    {
        if (CardRequest::query()->where('employee_id', $employee->id)->whereIn('status', CardRequest::PENDING)->exists()) {
            throw ValidationException::withMessages(['employee' => 'This employee already has a card request waiting for a decision.']);
        }
    }

    private function assertPending(CardRequest $request): void
    {
        if (! in_array($request->status, CardRequest::PENDING, true)) {
            throw ValidationException::withMessages(['request' => 'This request has already been decided.']);
        }
    }

    private function organizationId(Employee $employee): ?string
    {
        return $employee->currentAssignment?->organization_id;
    }
}
