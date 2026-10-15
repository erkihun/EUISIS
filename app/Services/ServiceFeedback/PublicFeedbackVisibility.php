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
     * The submitter's — or tipster's — details, or nothing plus a flag that
     * says so. A Tip-off's identity is confidential: it never appears in
     * aggregate views, the dashboard or a public page, only here and in an
     * export, both behind the identity grant (and audited by the caller).
     *
     * @return array{client_name: ?string, client_contact: ?string, client_email: ?string, submitter_organization: ?string, submitter_address: ?array<string, string>, identity_hidden: bool}
     */
    public function identity(User $user, EmployeeServiceFeedback $submission): array
    {
        if (! $this->canSeeIdentity($user)) {
            return ['client_name' => null, 'client_contact' => null, 'client_email' => null, 'submitter_organization' => null, 'submitter_address' => null, 'identity_hidden' => true];
        }

        return [
            'client_name' => $submission->client_name,
            'client_contact' => $submission->client_contact,
            'client_email' => $submission->client_email,
            'submitter_organization' => $submission->submitter_organization,
            'submitter_address' => $submission->submitter_address,
            'identity_hidden' => false,
        ];
    }

    /** Whether the submitter gave anything about themselves; an anonymous Tip-off did not. */
    public function hasIdentity(EmployeeServiceFeedback $submission): bool
    {
        foreach (['client_name', 'client_contact', 'client_email', 'submitter_organization', 'submitter_address'] as $field) {
            if (! empty($submission->{$field})) {
                return true;
            }
        }

        return false;
    }
}
