<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\IdCard;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The card lifecycle changes the Core makes from its workspace: suspend (a
 * reversible block), reinstate (back to active, under the activation rules) and
 * revoke (final). Each locks the card row, checks scope, and is audited with
 * its reason.
 */
final readonly class ChangeCardStatus
{
    /** transition => [allowed from, resulting status] */
    private const TRANSITIONS = [
        'suspend' => [[IdCard::ACTIVE, IdCard::ISSUED], IdCard::SUSPENDED],
        'reinstate' => [[IdCard::SUSPENDED], IdCard::ACTIVE],
        'revoke' => [[IdCard::ACTIVE, IdCard::ISSUED, IdCard::SUSPENDED, 'printed', 'pending_print'], IdCard::REVOKED],
    ];

    public function __construct(private OrganizationScope $scope, private AuditWriter $audit) {}

    public function execute(IdCard $card, string $transition, string $reason, User $actor): IdCard
    {
        [$from, $to] = self::TRANSITIONS[$transition] ?? throw ValidationException::withMessages(['transition' => 'Unknown card change.']);

        return DB::transaction(function () use ($card, $transition, $reason, $actor, $from, $to): IdCard {
            $card = IdCard::query()->with('employee.currentAssignment')->lockForUpdate()->findOrFail($card->id);
            $organizationId = $card->employee?->currentAssignment?->organization_id;

            if (! $this->scope->allows($actor, $organizationId === null ? null : (string) $organizationId)) {
                throw ValidationException::withMessages(['card' => 'The card holder is outside your organizations.']);
            }
            if (! in_array($card->status, $from, true)) {
                throw ValidationException::withMessages(['card' => "A {$card->status} card cannot be {$transition}d."]);
            }
            if ($transition === 'reinstate') {
                $otherActive = IdCard::query()->where('employee_id', $card->employee_id)->whereKeyNot($card->id)->where('status', IdCard::ACTIVE)->exists();
                if ($card->employee === null || $card->employee->isSeparated() || $card->employee->status !== 'active' || $otherActive) {
                    throw ValidationException::withMessages(['card' => 'Only the active employee\'s only card can be reinstated.']);
                }
            }

            $old = $card->status;
            $card->forceFill(array_filter([
                'status' => $to,
                'revoked_at' => $transition === 'revoke' ? now() : null,
                'revoke_reason' => $transition === 'revoke' ? $reason : null,
                'is_current' => $transition === 'revoke' ? false : null,
            ], fn ($value) => $value !== null))->save();

            $this->audit->write('card.'.$transition, $actor, $card, $organizationId === null ? null : (string) $organizationId, ['status' => $old], ['status' => $to], $reason);

            return $card->fresh();
        });
    }
}
