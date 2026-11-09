<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Enums\OrganizationScopeType;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Modules\ServiceMonitoring\Domain\Models\SupportAssignment;

/**
 * Who may see and do what in Service Delivery Monitoring
 * (docs/service-monitoring-security.md), mirroring FieldWorkAccess. Every
 * check pairs the permission with OrganizationScopeService, always against
 * the RECORD's organization (the submission's snapshot, the participant's
 * organization), never an id the request supplied.
 *
 *   institution  submit / attest / view inside the scope of the participant's organization
 *   sub-city     review of woreda reports and certification inside the sub-city's scope
 *   city         review of sub-city and center reports, technology verification, period
 *                locking and reopening, the registry and settings need city-wide reach
 *   support      assigned support officers see their participants' reports and threads
 *
 * Policies call these methods; controllers never decide access on their own.
 */
class ServiceMonitoringAccess
{
    /** @var array<int, bool> */
    private array $citywide = [];

    /** @var array<int, list<string>> */
    private array $supported = [];

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

    /** @return list<string> participant ids the user is assigned to support */
    public function supportedParticipantIds(User $user): array
    {
        return $this->supported[$user->id] ??= SupportAssignment::query()->where('user_id', $user->id)->pluck('participant_id')->map(fn ($id): string => (string) $id)->all();
    }

    public function supports(User $user, string $participantId): bool
    {
        return in_array($participantId, $this->supportedParticipantIds($user), true);
    }

    /**
     * Participants whose reports the user may see: those of organizations in
     * scope, plus those the user supports.
     *
     * @param  Builder<Participant>  $query
     * @return Builder<Participant>
     */
    public function scopeParticipants(Builder $query, User $user): Builder
    {
        if ($this->isCitywide($user)) {
            return $query;
        }
        $organizations = $this->scope->allowedOrganizationIds($user);
        $supported = $this->supportedParticipantIds($user);

        return $query->where(fn (Builder $q): Builder => $q->whereIn('organization_id', $organizations)->orWhereIn('id', $supported));
    }

    /**
     * The same rule for submissions, on their organization snapshot.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeSubmissions(Builder $query, User $user): Builder
    {
        if ($this->isCitywide($user)) {
            return $query;
        }
        $organizations = $this->scope->allowedOrganizationIds($user);
        $supported = $this->supportedParticipantIds($user);

        return $query->where(fn (Builder $q): Builder => $q->whereIn('organization_id', $organizations)->orWhereIn('participant_id', $supported));
    }

    public function canViewModule(User $user): bool
    {
        return $user->can('service_monitoring.view');
    }

    public function canViewParticipant(User $user, Participant $participant): bool
    {
        return $user->can('service_monitoring.view')
            && ($this->reaches($user, $participant->organization_id) || $this->supports($user, $participant->id) || $this->isCitywide($user));
    }

    public function canViewSubmission(User $user, Submission $submission): bool
    {
        return $user->can('service_monitoring.view')
            && ($this->reaches($user, $submission->organization_id) || $this->supports($user, $submission->participant_id) || $this->isCitywide($user));
    }

    public function canEdit(User $user, Submission $submission): bool
    {
        return $user->can('service_monitoring.submit')
            && $this->reaches($user, $submission->organization_id)
            && $submission->isEditable()
            && ! $submission->period->isLocked();
    }

    public function canSubmit(User $user, Submission $submission): bool
    {
        return $this->canEdit($user, $submission) && $user->can('service_monitoring.attest');
    }

    /** Woreda reports: the sub-city's scope; sub-city and center reports: city-wide reach (D8). */
    public function hasReviewerReach(User $user, Submission $submission): bool
    {
        return $submission->level === ReportingLevel::Woreda
            ? $this->reaches($user, $submission->organization_id)
            : $this->isCitywide($user);
    }

    public function canReview(User $user, Submission $submission): bool
    {
        return $user->can('service_monitoring.review')
            && $submission->status === SubmissionStatus::Submitted
            && ! $submission->period->isLocked()
            && $submission->submitted_by !== $user->id
            && $this->hasReviewerReach($user, $submission);
    }

    public function canComment(User $user, Submission $submission): bool
    {
        return $user->can('service_monitoring.review') && $this->hasReviewerReach($user, $submission) && ! $submission->period->isLocked();
    }

    public function canViewNamed(User $user, Submission $submission): bool
    {
        return $user->can('service_monitoring.accountability.view_named')
            && ($this->reaches($user, $submission->organization_id) || $this->isCitywide($user));
    }

    public function canExportNamed(User $user): bool
    {
        return $user->can('service_monitoring.accountability.export_named');
    }

    public function canRequestCorrection(User $user, Submission $submission): bool
    {
        return $user->can('service_monitoring.submit')
            && $this->reaches($user, $submission->organization_id)
            && $submission->status === SubmissionStatus::Accepted
            && $submission->is_current
            && ! $submission->period->isLocked();
    }

    public function canDecideCorrection(User $user, Submission $submission): bool
    {
        return $user->can('service_monitoring.review') && $this->hasReviewerReach($user, $submission) && ! $submission->period->isLocked();
    }

    public function canManagePeriods(User $user): bool
    {
        return $user->can('service_monitoring.periods.manage') && $this->isCitywide($user);
    }

    public function canReopenPeriods(User $user): bool
    {
        return $user->can('service_monitoring.periods.reopen') && $this->isCitywide($user);
    }

    public function canManageParticipants(User $user): bool
    {
        return $user->can('service_monitoring.participants.manage') && $this->isCitywide($user);
    }

    public function canGrantExtension(User $user, Participant $participant): bool
    {
        return $user->can('service_monitoring.extensions.grant') && ($this->reaches($user, $participant->organization_id) || $this->isCitywide($user));
    }

    public function canVerifyTechnology(User $user): bool
    {
        return $user->can('service_monitoring.technology.verify') && $this->isCitywide($user);
    }

    public function canConsolidateSubCity(User $user, Participant $subCity): bool
    {
        return $user->can('service_monitoring.consolidate')
            && $subCity->level === ReportingLevel::SubCity
            && ($this->reaches($user, $subCity->organization_id) || $this->isCitywide($user));
    }

    public function canConsolidateCity(User $user): bool
    {
        return $user->can('service_monitoring.consolidate') && $this->isCitywide($user);
    }

    public function canOpenClarification(User $user, Participant $participant): bool
    {
        return $user->can('service_monitoring.view') && $this->reaches($user, $participant->organization_id);
    }

    public function canRespond(User $user, Clarification $clarification): bool
    {
        return $user->can('service_monitoring.support.respond')
            && ($this->supports($user, $clarification->participant_id) || $this->isCitywide($user));
    }

    public function canViewClarification(User $user, Clarification $clarification): bool
    {
        return $this->canRespond($user, $clarification)
            || ($user->can('service_monitoring.view') && $this->reaches($user, $clarification->participant?->organization_id));
    }

    public function canManageVisits(User $user, Participant $participant): bool
    {
        return $user->can('service_monitoring.visits.manage')
            && ($this->supports($user, $participant->id) || $this->isCitywide($user));
    }

    public function canViewVisit(User $user, MonitoringVisit $visit): bool
    {
        $participant = $visit->participant;

        return $participant !== null
            && ($this->canManageVisits($user, $participant)
                || ($user->can('service_monitoring.view') && $this->reaches($user, $participant->organization_id)));
    }

    /** The visiting team updates any item; the owning institution updates its own items' progress. */
    public function canUpdateActionItem(User $user, ActionItem $item): bool
    {
        $participant = $item->visit?->participant;
        if ($participant !== null && $this->canManageVisits($user, $participant)) {
            return true;
        }

        return $user->can('service_monitoring.submit') && $this->reaches($user, $item->owner_organization_id);
    }

    public function canViewReports(User $user): bool
    {
        return $user->can('service_monitoring.reports.view');
    }

    public function canExport(User $user): bool
    {
        return $user->can('service_monitoring.reports.export');
    }

    public function canManageSettings(User $user): bool
    {
        return $user->can('service_monitoring.settings.manage') && $this->isCitywide($user);
    }

    /** Form templates shape every institution's report, so building them is a city-wide duty. */
    public function canManageForms(User $user): bool
    {
        return $user->can('service_monitoring.forms.manage') && $this->isCitywide($user);
    }

    public function forget(): void
    {
        $this->citywide = [];
        $this->supported = [];
    }
}
