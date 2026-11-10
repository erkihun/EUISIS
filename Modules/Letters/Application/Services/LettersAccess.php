<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Enums\OrganizationScopeType;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Enums\RoutingStatus;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;
use Modules\Letters\Domain\Models\LetterTemplate;

/**
 * Who may see and do what with letters (ADR 0037). Every check pairs the
 * permission with OrganizationScopeService, always against the RECORD's
 * institution (the registry entry's organization, the letter's owner), never
 * an id the request supplied. Policies call these methods; controllers never
 * decide access on their own.
 */
class LettersAccess
{
    /** @var array<int, bool> */
    private array $citywide = [];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function isCitywide(User $user): bool
    {
        return $this->citywide[$user->id] ??= $this->scope->isUnrestricted($user)
            || $user->organizationScopes()->active()->where('scope_type', OrganizationScopeType::Citywide->value)->exists();
    }

    public function reaches(User $user, ?string $organizationId): bool
    {
        return $organizationId !== null && $this->scope->canAccessOrganization($user, $organizationId);
    }

    /** @return list<string> */
    public function organizationIds(User $user): array
    {
        return array_map('strval', $this->scope->allowedOrganizationIds($user));
    }

    /**
     * Registry entries the user may see: their institutions', with letters.view.
     *
     * @param  Builder<LetterRegistration>  $query
     * @return Builder<LetterRegistration>
     */
    public function scopeRegistrations(Builder $query, User $user): Builder
    {
        if (! $user->can('letters.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $this->isCitywide($user) ? $query : $query->whereIn('organization_id', $this->organizationIds($user));
    }

    /**
     * Letters the user may open: one of their institutions holds a registry
     * entry, or a routing step is addressed to them.
     *
     * @param  Builder<Letter>  $query
     * @return Builder<Letter>
     */
    public function scopeLetters(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            if ($user->can('letters.view')) {
                if ($this->isCitywide($user)) {
                    $q->whereRaw('1 = 1');
                } else {
                    $ids = $this->organizationIds($user);
                    $q->whereIn('organization_id', $ids)->orWhereHas('registrations', fn (Builder $r) => $r->whereIn('organization_id', $ids));
                }
            } else {
                $q->whereRaw('1 = 0');
            }
            $q->orWhereHas('routings', fn (Builder $r) => $r->where('to_user_id', $user->id));
        });
    }

    public function canView(User $user, Letter $letter): bool
    {
        if ($user->can('letters.view')) {
            if ($this->isCitywide($user) || $this->reaches($user, $letter->organization_id)) {
                return true;
            }
            foreach ($letter->registrations as $registration) {
                if ($this->isCitywide($user) || $this->reaches($user, $registration->organization_id)) {
                    return true;
                }
            }
        }

        return $this->isRoutedTo($user, $letter);
    }

    public function isRoutedTo(User $user, Letter $letter): bool
    {
        return $letter->routings->contains(fn (LetterRouting $r): bool => $r->to_user_id === $user->id);
    }

    /**
     * The contents (subject, body, files) of a confidential or secret letter
     * are for the people handling it and for holders of letters.view_confidential
     * whose scope reaches an institution on the letter. Public and internal
     * letters open to everyone who may see the registry entry.
     */
    public function canViewContents(User $user, Letter $letter): bool
    {
        if (! $this->canView($user, $letter)) {
            return false;
        }
        if (! $letter->isRestricted()) {
            return true;
        }
        if (in_array($user->id, [$letter->created_by, $letter->signatory_user_id, $letter->approved_by, $letter->dispatched_by], true) || $this->isRoutedTo($user, $letter)) {
            return true;
        }
        if (! $user->can('letters.view_confidential')) {
            return false;
        }
        if ($this->isCitywide($user) || $this->reaches($user, $letter->organization_id)) {
            return true;
        }
        foreach ($letter->registrations as $registration) {
            if ($this->reaches($user, $registration->organization_id)) {
                return true;
            }
        }

        return false;
    }

    /** The sender's desk records that an external recipient acknowledged receipt of a dispatched letter. */
    public function canAcknowledge(User $user, LetterRegistration $registration): bool
    {
        $letter = $registration->letter;

        return $letter !== null && $letter->status === LetterStatus::Dispatched
            && $registration->organization_id === $letter->organization_id && $registration->direction->value !== 'incoming'
            && $registration->acknowledged_at === null
            && ($this->canActFor($user, 'letters.dispatch', $registration->organization_id) || $this->canActFor($user, 'letters.register', $registration->organization_id));
    }

    /** Institutions the user may register or compose for (the pick-list of the forms). */
    public function canActFor(User $user, string $permission, ?string $organizationId): bool
    {
        return $user->can($permission) && $this->reaches($user, $organizationId);
    }

    public function canEdit(User $user, Letter $letter): bool
    {
        return $letter->isEditable() && $letter->isComposed()
            && $this->canActFor($user, 'letters.compose', $letter->organization_id);
    }

    public function canSubmit(User $user, Letter $letter): bool
    {
        return $this->canEdit($user, $letter);
    }

    /** The named signatory signs; anyone holding letters.approve in the institution may sign in their place. */
    public function canApprove(User $user, Letter $letter): bool
    {
        return $letter->status === LetterStatus::PendingApproval
            && $this->canActFor($user, 'letters.approve', $letter->organization_id);
    }

    public function canReturn(User $user, Letter $letter): bool
    {
        return $this->canApprove($user, $letter);
    }

    public function canDispatch(User $user, Letter $letter): bool
    {
        return $letter->status === LetterStatus::Approved
            && $this->canActFor($user, 'letters.dispatch', $letter->organization_id);
    }

    public function canCancel(User $user, Letter $letter): bool
    {
        return ! $letter->status->isFinal() && $letter->isComposed()
            && $this->canActFor($user, 'letters.compose', $letter->organization_id);
    }

    /** A reply is a new outgoing letter from the institution that received this one. */
    public function canReply(User $user, Letter $letter): bool
    {
        if (! $letter->status->isFinal() || $letter->status === LetterStatus::Cancelled) {
            return false;
        }
        foreach ($letter->registrations as $registration) {
            if ($registration->direction->value === 'incoming' && $this->canActFor($user, 'letters.compose', $registration->organization_id)) {
                return true;
            }
        }

        return false;
    }

    public function canAddAttachment(User $user, Letter $letter): bool
    {
        if ($letter->status->isFinal() && $letter->status !== LetterStatus::Received) {
            return false;
        }

        return $this->canActFor($user, 'letters.compose', $letter->organization_id)
            || $this->canActFor($user, 'letters.register', $letter->organization_id);
    }

    public function canReceive(User $user, LetterRegistration $registration): bool
    {
        return $registration->status === RegistrationStatus::PendingReceipt
            && $this->canActFor($user, 'letters.register', $registration->organization_id);
    }

    public function canRoute(User $user, LetterRegistration $registration): bool
    {
        return $registration->status->isOpen()
            && $this->canActFor($user, 'letters.route', $registration->organization_id);
    }

    public function canClose(User $user, LetterRegistration $registration): bool
    {
        return $registration->status->isOpen()
            && ($this->canActFor($user, 'letters.route', $registration->organization_id) || $this->canActFor($user, 'letters.register', $registration->organization_id));
    }

    /** The addressee acts on a routing step; the registry desk (letters.route) may act for a unit. */
    public function canActOnRouting(User $user, LetterRouting $routing): bool
    {
        if (! $routing->status->isOpen()) {
            return false;
        }
        if ($routing->to_user_id === $user->id && $user->can('letters.handle')) {
            return true;
        }
        $organizationId = $routing->registration?->organization_id;

        return $routing->to_user_id === null && $this->canActFor($user, 'letters.route', $organizationId)
            || $this->canActFor($user, 'letters.route', $organizationId) && $routing->status === RoutingStatus::Pending;
    }

    public function canViewTemplates(User $user): bool
    {
        return $user->can('letters.templates.view') || $user->can('letters.templates.manage') || $user->can('letters.templates.manage_platform');
    }

    public function canViewTemplate(User $user, LetterTemplate $template): bool
    {
        if (! $this->canViewTemplates($user)) {
            return false;
        }

        return $template->isPlatform() || $this->isCitywide($user) || $this->reaches($user, $template->owner_organization_id);
    }

    public function canManageTemplate(User $user, ?string $ownerOrganizationId): bool
    {
        return $ownerOrganizationId === null
            ? $user->can('letters.templates.manage_platform') && $this->isCitywide($user)
            : $this->canActFor($user, 'letters.templates.manage', $ownerOrganizationId);
    }

    /**
     * Templates offered to the user: platform ones and those of institutions in scope.
     *
     * @param  Builder<LetterTemplate>  $query
     * @return Builder<LetterTemplate>
     */
    public function scopeTemplates(Builder $query, User $user): Builder
    {
        if ($this->isCitywide($user)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereNull('owner_organization_id')->orWhereIn('owner_organization_id', $this->organizationIds($user)));
    }

    public function canManageNumbering(User $user, ?string $organizationId): bool
    {
        return $organizationId === null
            ? $user->can('letters.numbering.manage') && $this->isCitywide($user)
            : $this->canActFor($user, 'letters.numbering.manage', $organizationId);
    }

    /** Reply-time standards and the file scheme: the institution's administrator; the platform defaults need city-wide reach. */
    public function canManageStandards(User $user, ?string $organizationId): bool
    {
        return $organizationId === null
            ? $user->can('letters.standards.manage') && $this->isCitywide($user)
            : $this->canActFor($user, 'letters.standards.manage', $organizationId);
    }

    /** An institution's letterhead is its administrator's; the platform default needs city-wide reach. */
    public function canManageLetterhead(User $user, ?string $organizationId): bool
    {
        return $organizationId === null
            ? $user->can('letters.letterhead.manage') && $this->isCitywide($user)
            : $this->canActFor($user, 'letters.letterhead.manage', $organizationId);
    }
}
