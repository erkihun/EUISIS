<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\AuditEventType;
use App\Enums\CommitteeType;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceRoute;
use App\Models\Organization;
use App\Models\User;
use App\Services\Grievances\GrievanceAudit;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Grievances\GrievanceCommitteeService;
use App\Services\Grievances\GrievanceSettings;
use App\Support\Demo\DemoDataset;

/**
 * A small Grievance Management dataset for Organization 5, through the
 * grievance services and with separation of duties as in production:
 *
 *   - a grievance committee (chairperson, writer, member — existing demo
 *     employees) created by the DEMO Grievance Administrator and approved by
 *     the DEMO City Admin;
 *   - routes: Organization 5 → its committee → Organization 4's Customer
 *     Service Team → Organization 4's Human Resource Directorate (routes and
 *     categories are saved as the settings pages save them, then approved by
 *     a second person);
 *   - two cases by a demo employee: one submitted and waiting at intake, one
 *     accepted, routed to the committee and under review by the chairperson.
 *
 * Not seeded: an escalation-ready case (needs an SLA clock that has run out)
 * and a decision (drafting, approval and issue workflow). All narratives are
 * clearly synthetic.
 */
class DemoGrievanceSeeder extends DemoSeeder
{
    public const ORGANIZATION = 'ORG-5';

    public const CATEGORY_CODE = 'DEMO-WORK-CONDITIONS';

    private const SUBMITTED_SUBJECT = 'DEMO: request for a workplace ergonomics review';

    private const UNDER_REVIEW_SUBJECT = 'DEMO: disagreement over overtime scheduling';

    public function run(GrievanceSettings $settings, GrievanceCommitteeService $committees, GrievanceCaseService $cases, GrievanceAudit $audit): void
    {
        if (! $settings->enabled()) {
            $this->command?->warn('Grievance Management is disabled in settings: SKIPPED.');

            return;
        }

        $organization = DemoDataset::requireOrganization(self::ORGANIZATION);
        $admin = DemoDataset::requireUser('demo.grievance.admin@example.test');
        $approver = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);

        $category = $this->category($admin, $audit);
        $committee = $this->committee($organization, $admin, $approver, $committees);
        $this->routes($organization, $committee, $admin, $approver, $audit);
        $this->cases($category, $cases);
    }

    private function category(User $admin, GrievanceAudit $audit): GrievanceCategory
    {
        $category = GrievanceCategory::query()->where('code', self::CATEGORY_CODE)->first();
        if ($category !== null) {
            return $category;
        }

        $category = new GrievanceCategory;
        $category->fill([
            'code' => self::CATEGORY_CODE,
            'name_en' => 'DEMO Working conditions',
            'name_am' => 'ማሳያ የሥራ ሁኔታ',
            'description_en' => 'Synthetic demo category.',
            'is_active' => true,
            'requires_executive_approval' => false,
            'sort_order' => 0,
        ])->save();
        $audit->record(AuditEventType::GrievanceConfigurationChanged, $admin, $category, ['category' => $category->code]);

        return $category;
    }

    private function committee(Organization $organization, User $admin, User $approver, GrievanceCommitteeService $committees): GrievanceCommittee
    {
        $existing = GrievanceCommittee::query()->where('organization_id', $organization->id)
            ->where('committee_type', CommitteeType::Grievance->value)->where('name_en', 'like', 'DEMO %')->first();
        if ($existing !== null) {
            return $existing;
        }

        $from = DemoDataset::epoch()->toDateString();
        $committee = $committees->create($admin, [
            'organization_id' => $organization->id,
            'committee_type' => CommitteeType::Grievance->value,
            'name_en' => 'DEMO Records Agency Grievance Committee',
            'name_am' => 'ማሳያ የመዛግብት ኤጀንሲ የቅሬታ ሰሚ ኮሚቴ',
            'description_en' => 'Synthetic demo committee.',
            'effective_from' => $from,
        ]);

        foreach (['E-5-2' => 'chairperson', 'E-5-3' => 'writer', 'E-5-4' => 'member'] as $employeeKey => $role) {
            $committees->addMember($committee, $admin, [
                'employee_id' => DemoDataset::requireEmployee($employeeKey)->id,
                'role' => $role,
                'effective_from' => $from,
                'appointment_reference' => 'DEMO-APPOINTMENT',
            ]);
        }

        return $committees->approve($committee->fresh(), $approver);
    }

    private function routes(Organization $organization, GrievanceCommittee $committee, User $admin, User $approver, GrievanceAudit $audit): void
    {
        $org4 = DemoDataset::requireOrganization('ORG-4');
        $team = DemoDataset::unit($org4, 'SVC-CS');
        $directorate = DemoDataset::unit($org4, 'HR');

        $routes = [
            ['organization', $organization->id, 'committee', $committee->id, 'initial_assignment'],
            ['committee', $committee->id, 'organization_unit', $team->id, 'timeout_escalation'],
            ['organization_unit', $team->id, 'organization_unit', $directorate->id, 'timeout_escalation'],
        ];

        foreach ($routes as [$sourceType, $sourceId, $targetType, $targetId, $movement]) {
            $exists = GrievanceRoute::query()->where('source_handler_type', $sourceType)->where('source_handler_id', $sourceId)
                ->where('target_handler_type', $targetType)->where('target_handler_id', $targetId)->where('movement_type', $movement)->exists();
            if ($exists) {
                continue;
            }

            // As GrievanceRoutingController saves and then approves a route.
            $data = [
                'source_handler_type' => $sourceType, 'source_handler_id' => $sourceId, 'include_descendants' => false,
                'target_handler_type' => $targetType, 'target_handler_id' => $targetId, 'movement_type' => $movement,
                'priority' => 10, 'effective_from' => DemoDataset::epoch()->toDateString(),
                'notes' => 'Synthetic demo route.',
            ];
            $route = GrievanceRoute::query()->create([...$data, 'is_active' => true, 'created_by' => $admin->id, 'approved_at' => null]);
            $audit->record(AuditEventType::GrievanceRouteSaved, $admin, $route, collect($data)->except('notes')->all());

            $route->forceFill(['approved_at' => now(), 'approved_by' => $approver->id])->save();
            $audit->record(AuditEventType::GrievanceRouteApproved, $approver, $route, ['route_id' => $route->getKey()]);
        }
    }

    private function cases(GrievanceCategory $category, GrievanceCaseService $cases): void
    {
        $complainant = DemoDataset::requireUser('demo.org5.employee@example.test');
        $employee = DemoDataset::requireEmployee('E-5-6');
        $incident = DemoDataset::anchor()->subDays(10)->toDateString();

        $file = function (string $subject) use ($cases, $complainant, $employee, $category, $incident): ?Grievance {
            if (Grievance::query()->where('employee_id', $employee->id)->where('subject', $subject)->exists()) {
                return null;
            }
            $draft = $cases->createDraft($complainant, [
                'category_id' => $category->id,
                'subject' => $subject,
                'description' => 'Synthetic demo grievance text for testing only. It describes no real person or event.',
                'incident_date' => $incident,
                'respondent_description' => 'Synthetic demo respondent',
            ]);

            return $cases->submit($draft, $complainant);
        };

        // 1. Submitted, waiting at intake.
        $file(self::SUBMITTED_SUBJECT);

        // 2. Accepted at intake, routed to the committee, under review.
        $grievance = $file(self::UNDER_REVIEW_SUBJECT);
        if ($grievance !== null) {
            $grievance = $cases->intakeAccept($grievance, DemoDataset::requireUser('demo.grievance.officer@example.test'), 'Synthetic demo intake: accepted');
            $cases->startReview($grievance->fresh(), DemoDataset::requireUser('demo.org5.chair@example.test'));
        }
    }
}
