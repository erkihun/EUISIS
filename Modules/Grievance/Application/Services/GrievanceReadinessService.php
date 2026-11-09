<?php

declare(strict_types=1);

namespace Modules\Grievance\Application\Services;

use App\Backbone\Contracts\OrganizationDirectory;
use App\Models\OrganizationSeal;
use App\Models\User;
use App\Services\Legal\StatutoryCommitteeRules;
use Illuminate\Database\Eloquent\Builder;
use Modules\Grievance\Domain\Models\GrievanceApprovalRule;
use Modules\Grievance\Domain\Models\GrievanceCommittee;
use Modules\Grievance\Domain\Models\GrievanceHandlerUnit;
use Modules\Grievance\Domain\Models\GrievanceRoute;
use Modules\Grievance\Domain\Models\GrievanceSlaProfile;

/**
 * What an institution still has to set up before an employee's grievance can
 * run from filing to an issued decision and an appeal (owner request
 * 2026-11-08, after the member sign-in walkthrough). Each check names a
 * step that otherwise stops a case with an error:
 *
 *  - committee: an active grievance committee whose composition meets
 *    Regulation 201/2018 Art. 30(2);
 *  - approval_rule: a rule sending committee decisions to the head's
 *    position, held by someone who can approve;
 *  - seal: an active institution seal (decision letters, Art. 35(2));
 *  - appeal_route: a route taking an appealed committee decision onward;
 *  - member_accounts: every serving committee, team and directorate member
 *    has an active account.
 *
 * System-wide: a designated Grievance Directorate and an appeal-filing
 * deadline profile.
 */
final class GrievanceReadinessService
{
    public const CHECKS = ['committee', 'approval_rule', 'seal', 'appeal_route', 'member_accounts'];

    public function __construct(
        private readonly OrganizationDirectory $organizations,
        private readonly StatutoryCommitteeRules $rules,
        private readonly GrievanceApproverResolver $approvers,
    ) {}

    public function canSee(User $user): bool
    {
        return $user->can('grievance_settings.view') || $user->can('grievance_committees.manage');
    }

    /**
     * @return array{system: array<string, bool>, organizations: list<array{id: string, name: string, checks: array<string, bool>, missing_accounts: int}>}
     */
    public function forUser(User $user, int $limit = 100): array
    {
        $locale = app()->getLocale();
        $rows = [];
        foreach ($this->organizations->activeFor($user, $limit) as $org) {
            $rows[] = [
                'id' => $org->organizationUuid,
                'name' => (string) ($locale === 'am' ? ($org->nameAm ?: $org->nameEn) : ($org->nameEn ?: $org->nameAm)),
                ...$this->forOrganization($org->organizationUuid),
            ];
        }
        // Institutions with something to fix first, then by name.
        usort($rows, fn (array $a, array $b): int => [count(array_filter($a['checks'])), $a['name']] <=> [count(array_filter($b['checks'])), $b['name']]);

        return [
            'system' => [
                'directorate' => GrievanceHandlerUnit::query()->where('kind', 'directorate')->where('status', 'active')->exists(),
                'appeal_deadline' => GrievanceSlaProfile::query()->where('purpose', 'appeal_filing')->where('is_active', true)->exists(),
            ],
            'organizations' => $rows,
        ];
    }

    /** @return array{checks: array<string, bool>, missing_accounts: int} */
    public function forOrganization(string $organizationId): array
    {
        $committees = GrievanceCommittee::query()->where('organization_id', $organizationId)->where('committee_type', 'grievance')->where('status', 'active')->get();
        $readyCommittee = $committees->contains(fn (GrievanceCommittee $c): bool => $this->rules->compositionProblems($c) === []);

        $missingAccounts = $this->membersWithoutAccount($organizationId, $committees->pluck('id')->all());

        return [
            'checks' => [
                'committee' => $readyCommittee,
                'approval_rule' => $this->hasApprovalRule($organizationId),
                'seal' => OrganizationSeal::query()->where('organization_id', $organizationId)->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()))
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()))->exists(),
                'appeal_route' => $this->hasAppealRoute($committees->pluck('id')->map(fn ($id) => (string) $id)->all()),
                'member_accounts' => $missingAccounts === 0 && $committees->isNotEmpty(),
            ],
            'missing_accounts' => $missingAccounts,
        ];
    }

    private function hasApprovalRule(string $organizationId): bool
    {
        $rules = GrievanceApprovalRule::query()->where('is_active', true)->where('requires_approval', true)
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->where(fn ($q) => $q->whereNull('handler_type')->orWhere('handler_type', 'committee'))
            ->whereDate('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()))
            ->get();

        return $rules->contains(fn (GrievanceApprovalRule $rule): bool => $this->approvers->approversFor($rule->approver_position_id, $organizationId) !== []);
    }

    /** @param  list<string>  $committeeIds */
    private function hasAppealRoute(array $committeeIds): bool
    {
        if ($committeeIds === []) {
            return false;
        }

        return GrievanceRoute::query()->where('is_active', true)->where('movement_type', 'employee_appeal')->where('source_handler_type', 'committee')
            ->where(fn (Builder $q) => $q->where('source_all', true)->orWhereIn('source_handler_id', $committeeIds))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()))
            ->exists();
    }

    /** @param  list<string>  $committeeIds  the institution's active grievance committees */
    private function membersWithoutAccount(string $organizationId, array $committeeIds): int
    {
        $employeeIds = collect();
        foreach (GrievanceCommittee::query()->whereIn('id', $committeeIds)->get() as $committee) {
            $employeeIds = $employeeIds->merge($committee->members()->servingOn(now())->pluck('employee_id'));
        }
        foreach (GrievanceHandlerUnit::query()->where('organization_id', $organizationId)->where('status', 'active')->get() as $unit) {
            $employeeIds = $employeeIds->merge($unit->members()->servingOn(now())->pluck('employee_id'));
        }
        $employeeIds = $employeeIds->map(fn ($id) => (string) $id)->unique()->values();
        if ($employeeIds->isEmpty()) {
            return 0;
        }
        $withAccount = User::query()->where('status', 'active')->whereIn('employee_id', $employeeIds)->pluck('employee_id')->map(fn ($id) => (string) $id)->unique();

        return $employeeIds->diff($withAccount)->count();
    }
}
