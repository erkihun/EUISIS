<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssessmentForm;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Assessment forms: permission says WHAT, organization scope says WHERE.
 *
 * A city-wide form (no organization) is visible to every holder of the view
 * permission and managed only by unrestricted administrators. An
 * organization's form is visible and managed inside that organization's
 * scope only.
 */
class AssessmentFormPolicy
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('assessment_forms.view');
    }

    public function view(User $user, AssessmentForm $form): bool
    {
        return $user->can('assessment_forms.view')
            && ($form->organization_id === null || $this->inScope($user, $form->organization_id));
    }

    /** Create a form owned by this organization (null: city-wide). */
    public function createFor(User $user, ?string $organizationId): bool
    {
        return $user->can('assessment_forms.create') && $this->manages($user, $organizationId);
    }

    public function update(User $user, AssessmentForm $form): bool
    {
        return $user->can('assessment_forms.edit_draft') && $this->manages($user, $form->organization_id);
    }

    public function publish(User $user, AssessmentForm $form): bool
    {
        return $user->can('assessment_forms.publish') && $this->manages($user, $form->organization_id);
    }

    public function archive(User $user, AssessmentForm $form): bool
    {
        return $user->can('assessment_forms.archive') && $this->manages($user, $form->organization_id);
    }

    public function cloneForm(User $user, AssessmentForm $form): bool
    {
        return $user->can('assessment_forms.create') && $this->view($user, $form);
    }

    private function manages(User $user, ?string $organizationId): bool
    {
        if ($this->scope->isUnrestricted($user)) {
            return true;
        }

        return $organizationId !== null && $this->inScope($user, $organizationId);
    }

    private function inScope(User $user, string $organizationId): bool
    {
        return $this->scope->isUnrestricted($user) || $this->scope->canAccessOrganization($user, $organizationId);
    }
}
