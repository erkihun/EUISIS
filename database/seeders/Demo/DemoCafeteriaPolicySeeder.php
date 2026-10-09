<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\CafeteriaGrantStatus;
use App\Enums\CafeteriaPolicyStatus;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServicePolicy;
use App\Models\Organization;
use App\Models\User;
use App\Services\Cafeteria\Policy\CafeteriaPolicyWorkflowService;
use App\Support\Demo\DemoDataset;
use RuntimeException;

/**
 * One organization-specific service policy per demo organization — there is
 * no global subsidy — through the policy workflow: draft and submit by the
 * DEMO City Admin, approval by a different person (maker-checker).
 *
 * Organization 5 gets a version history: v1 from the start of the dataset,
 * then v2 drafted with Create New Version and starting 14 days before the
 * first run. Approving v2 ends v1 the day before and marks it superseded.
 *
 * All amounts are SYNTHETIC DEMO VALUES, not official policy.
 */
class DemoCafeteriaPolicySeeder extends DemoSeeder
{
    public function run(CafeteriaPolicyWorkflowService $workflow): void
    {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);
        $checker = DemoDataset::requireUser(DemoDataset::CHECKER_EMAIL);

        foreach (DemoDataset::policies() as $organizationKey => $versions) {
            $organization = DemoDataset::requireOrganization($organizationKey);
            $assignment = $this->assignment($organization, $organizationKey);

            $first = CafeteriaServicePolicy::query()->where('cafeteria_service_assignment_id', $assignment->id)
                ->where('version_no', 1)->first();
            if ($first === null) {
                $first = $workflow->createDraft([
                    ...$versions[0],
                    'cafeteria_service_assignment_id' => $assignment->id,
                    'effective_from' => DemoDataset::epoch()->toDateString(),
                    'notes' => 'SYNTHETIC DEMO POLICY — not official policy.',
                ], $maker);
                $this->submitAndApprove($workflow, $first, $maker, $checker);
            }

            if (isset($versions[1]) && ! CafeteriaServicePolicy::query()->where('policy_group_id', $first->policy_group_id)->where('version_no', 2)->exists()) {
                $second = $workflow->createNewVersion($first->fresh(), $maker);
                $second = $workflow->updateDraft($second, [
                    ...$versions[1],
                    'effective_from' => DemoDataset::anchor()->subDays(DemoDataset::POLICY_V2_DAYS_AGO)->toDateString(),
                    'notes' => 'SYNTHETIC DEMO POLICY v2 — not official policy.',
                ], $maker);
                $this->submitAndApprove($workflow, $second, $maker, $checker);
            }
        }
    }

    private function submitAndApprove(CafeteriaPolicyWorkflowService $workflow, CafeteriaServicePolicy $policy, User $maker, User $checker): void
    {
        if ($policy->status === CafeteriaPolicyStatus::Draft) {
            $policy = $workflow->submit($policy, $maker);
        }

        $workflow->approve($policy, $checker);
    }

    private function assignment(Organization $organization, string $organizationKey): CafeteriaServiceAssignment
    {
        $definition = DemoDataset::serviceAssignments()[$organizationKey];
        $networkId = $definition['network'] !== null ? DemoDataset::network($definition['network'])?->id : null;

        return CafeteriaServiceAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('provider_id', DemoDataset::provider($definition['provider'])?->id)
            ->where(fn ($q) => $networkId === null ? $q->whereNull('cafeteria_service_network_id') : $q->where('cafeteria_service_network_id', $networkId))
            ->where('status', CafeteriaGrantStatus::Active->value)
            ->first() ?? throw new RuntimeException("Active demo service assignment for {$organizationKey} is missing.");
    }
}
