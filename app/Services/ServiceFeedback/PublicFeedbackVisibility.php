<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\PublicFeedbackTargetType;
use App\Models\EmployeeServiceFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Granular visibility over public submissions (brief §36, 55–57, 86).
 * Seeing a submission is one grant; seeing who sent it, its evidence, and
 * each target type are separate grants, so city-level oversight can read
 * the record without the complainant's phone number.
 */
final class PublicFeedbackVisibility
{
    public const IDENTITY = 'service_feedback.view_submitter_identity';

    public const ATTACHMENTS = 'service_feedback.view_sensitive_attachments';

    public const EMPLOYEE_TARGET = 'service_feedback.view_employee_target';

    public const ORGANIZATION_TARGET = 'service_feedback.view_organization_target';

    public function canSeeIdentity(User $user): bool
    {
        return $user->hasAnyPermission([self::IDENTITY]);
    }

    public function canSeeAttachments(User $user): bool
    {
        return $user->hasAnyPermission([self::ATTACHMENTS]);
    }

    /** Rows written before the unified model carry no target type and are employee submissions. */
    public function canSeeTarget(User $user, ?PublicFeedbackTargetType $target): bool
    {
        return in_array(($target ?? PublicFeedbackTargetType::Employee)->value, $this->visibleTargetTypes($user), true);
    }

    /** @return list<string> */
    public function visibleTargetTypes(User $user): array
    {
        $types = [];
        if ($user->hasAnyPermission([self::EMPLOYEE_TARGET])) {
            $types[] = PublicFeedbackTargetType::Employee->value;
        }
        if ($user->hasAnyPermission([self::ORGANIZATION_TARGET])) {
            $types[] = PublicFeedbackTargetType::Organization->value;
        }

        return $types;
    }

    /**
     * Confine a submissions query to the target types the user may view.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Builder<EmployeeServiceFeedback>
     */
    public function applyTargetScope(Builder $query, User $user): Builder
    {
        $types = $this->visibleTargetTypes($user);

        if (count($types) === 2) {
            return $query;
        }
        if ($types === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($types): void {
            $q->whereIn('target_type', $types);
            if (in_array(PublicFeedbackTargetType::Employee->value, $types, true)) {
                $q->orWhereNull('target_type');
            }
        });
    }

    /**
     * The submitter's name and contact, or nothing plus a flag that says so.
     *
     * @return array{client_name: ?string, client_contact: ?string, identity_hidden: bool}
     */
    public function identity(User $user, EmployeeServiceFeedback $submission): array
    {
        if (! $this->canSeeIdentity($user)) {
            return ['client_name' => null, 'client_contact' => null, 'identity_hidden' => true];
        }

        return ['client_name' => $submission->client_name, 'client_contact' => $submission->client_contact, 'identity_hidden' => false];
    }
}
