<?php

declare(strict_types=1);

namespace App\Policies;

use App\Actions\ChangeCardStatus;
use App\Models\IdCard;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationScope;

/**
 * ID cards (ADR 0009): each step its own permission, inside the holder's
 * organization, from a status the step may start from (ChangeCardStatus's
 * own table, which it enforces again).
 */
final class IdCardPolicy
{
    use ChecksOrganizationScope;

    public function view(User $user, IdCard $card): bool
    {
        return $this->permits($user, 'cards.view', $this->employeeOrganization((string) $card->employee_id));
    }

    public function transition(User $user, IdCard $card, string $transition): bool
    {
        $permission = ChangeCardStatus::PERMISSIONS[$transition] ?? null;

        return $permission !== null && $this->permits($user, $permission, $this->employeeOrganization((string) $card->employee_id),
            in_array($card->status, ChangeCardStatus::from($transition), true)
            // printing a printed card again needs an approved reprint (ADR 0010)
            && ! ($transition === 'print' && $card->status === IdCard::PRINTED && ! $card->reprint_required));
    }

    /** The quality check of a printed card (ADR 0010); ManagePrintBatches also refuses its printer. */
    public function qualityCheck(User $user, IdCard $card): bool
    {
        return $this->permits($user, 'cards.quality_check', $this->employeeOrganization((string) $card->employee_id),
            $card->status === IdCard::PRINTED && $card->qc_status === 'pending');
    }

    public function reprint(User $user, IdCard $card): bool
    {
        return $this->permits($user, 'cards.reprint', $this->employeeOrganization((string) $card->employee_id), $card->status === IdCard::PRINTED && $card->is_current);
    }

    /** issue, rotate or revoke the card's credential (ADR 0010) */
    public function credential(User $user, IdCard $card, string $step): bool
    {
        return in_array($step, ['issue', 'rotate', 'revoke'], true)
            && $this->permits($user, 'digital_credentials.'.$step, $this->employeeOrganization((string) $card->employee_id), $card->is_current);
    }

    public function replace(User $user, IdCard $card): bool
    {
        return $this->permits($user, 'id-cards.replace', $this->employeeOrganization((string) $card->employee_id),
            $card->is_current && in_array($card->status, IdCard::REPLACEABLE, true));
    }
}
