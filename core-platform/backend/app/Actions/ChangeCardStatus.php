<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every card status change after approval, as in EUISIS: print (pending print →
 * printed), issue (printed → issued), activate (issued → active, one active card
 * per active employee), report lost or damaged, suspend (the reversible block),
 * reinstate (back to active, under the activation rules) and revoke (final).
 * Each locks the card row, checks scope and is audited with its reason.
 */
final readonly class ChangeCardStatus
{
    /** transition => [allowed from, resulting status, timestamp column] */
    private const TRANSITIONS = [
        'print' => [[IdCard::PENDING_PRINT], IdCard::PRINTED, 'printed_at'],
        'issue' => [[IdCard::PRINTED], IdCard::ISSUED, 'issued_at'],
        'activate' => [[IdCard::ISSUED], IdCard::ACTIVE, 'activated_at'],
        'report_lost' => [[IdCard::ACTIVE, IdCard::ISSUED], IdCard::LOST, null],
        'report_damaged' => [[IdCard::ACTIVE, IdCard::ISSUED], IdCard::DAMAGED, null],
        'suspend' => [[IdCard::ACTIVE, IdCard::ISSUED], IdCard::SUSPENDED, null],
        'reinstate' => [[IdCard::SUSPENDED], IdCard::ACTIVE, null],
        'revoke' => [[IdCard::ACTIVE, IdCard::ISSUED, IdCard::SUSPENDED, IdCard::PRINTED, IdCard::PENDING_PRINT, IdCard::LOST, IdCard::DAMAGED], IdCard::REVOKED, 'revoked_at'],
    ];

    /** Changes that need a written reason (the routine production steps do not). */
    public const NEEDS_REASON = ['report_lost', 'report_damaged', 'suspend', 'reinstate', 'revoke'];

    public function __construct(private OrganizationScope $scope, private AuditWriter $audit) {}

    /** @return list<string> */
    public static function transitions(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public function execute(IdCard $card, string $transition, ?string $reason, User $actor): IdCard
    {
        [$from, $to, $stamp] = self::TRANSITIONS[$transition] ?? throw ValidationException::withMessages(['transition' => 'Unknown card change.']);

        return DB::transaction(function () use ($card, $transition, $reason, $actor, $from, $to, $stamp): IdCard {
            // Serialize all credential changes with requests and employee lifecycle changes.
            $employee = Employee::query()->lockForUpdate()->findOrFail($card->employee_id);
            $card = IdCard::query()->lockForUpdate()->findOrFail($card->id);
            $card->setRelation('employee', $employee->load('currentAssignment'));
            $organizationId = $card->employee?->currentAssignment?->organization_id;

            if (! $this->scope->allows($actor, $organizationId === null ? null : (string) $organizationId)) {
                throw ValidationException::withMessages(['card' => 'The card holder is outside your organizations.']);
            }
            if (! in_array($card->status, $from, true)) {
                throw ValidationException::withMessages(['card' => 'A '.str_replace('_', ' ', $card->status).' card cannot be changed this way ('.str_replace('_', ' ', $transition).').']);
            }
            if (in_array($transition, ['activate', 'reinstate'], true)) {
                if (! $card->is_current || $card->revoked_at !== null
                    || ($card->expires_at !== null && ! $card->expires_at->isFuture())
                    || ($card->qr_status ?? 'active') !== 'active') {
                    throw ValidationException::withMessages(['card' => 'Only a current, unexpired, unrevoked credential with an active QR can be activated.']);
                }
                $assignment = $employee->currentAssignment;
                if ($assignment === null || $assignment->employee_id !== $employee->id || ! $assignment->isEffective()) {
                    throw ValidationException::withMessages(['card' => 'An effective current assignment is required to activate a card.']);
                }
                $otherActive = IdCard::query()->where('employee_id', $card->employee_id)->whereKeyNot($card->id)->where('status', IdCard::ACTIVE)->exists();
                if ($card->employee === null || $card->employee->status !== 'active' || $otherActive) {
                    throw ValidationException::withMessages(['card' => 'Only an active employee\'s only card can be made active.']);
                }
            }

            $old = $card->status;
            $changes = ['status' => $to];
            if ($stamp !== null) {
                $changes[$stamp] = now();
            }
            if ($transition === 'revoke') {
                $changes['revoke_reason'] = $reason;
                $changes['is_current'] = false;
            }
            $card->forceFill($changes)->save();

            $this->audit->write('card.'.$transition, $actor, $card, $organizationId === null ? null : (string) $organizationId, ['status' => $old], ['status' => $to], $reason);

            return $card->fresh();
        });
    }
}
