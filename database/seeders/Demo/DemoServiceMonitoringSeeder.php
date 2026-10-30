<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Organizations\CreateOrganizationAction;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Support\Demo\DemoDataset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Modules\ServiceMonitoring\Application\Actions\AcceptSubmission;
use Modules\ServiceMonitoring\Application\Actions\CreateFiscalYear;
use Modules\ServiceMonitoring\Application\Actions\RegisterParticipant;
use Modules\ServiceMonitoring\Application\Actions\ReturnSubmission;
use Modules\ServiceMonitoring\Application\Actions\SaveSection1Entry;
use Modules\ServiceMonitoring\Application\Actions\SaveSection2;
use Modules\ServiceMonitoring\Application\Actions\SaveSection4;
use Modules\ServiceMonitoring\Application\Actions\SetNothingToReport;
use Modules\ServiceMonitoring\Application\Actions\StartSubmission;
use Modules\ServiceMonitoring\Application\Actions\SubmitSubmission;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Service Delivery Monitoring demo data (docs/service-monitoring.md):
 * FY 2019 E.C. and its periods, a sample sub-city with two woredas plus the
 * demo Civic Services Bureau as a center institution, and first-quarter
 * reports in mixed states (accepted, submitted, returned) plus a second-
 * quarter draft. Everything goes through the module's actions.
 *
 * Reports are made "as of" two days after the period opened (the clock is
 * set for the run and restored), so the deadline rules apply as they would
 * have. Idempotent: an existing report is left as it is.
 */
class DemoServiceMonitoringSeeder extends DemoSeeder
{
    private const ORGANIZATIONS = [
        'SM-SUB' => ['type' => 'SUB_CITY', 'name_en' => 'DEMO Arada Sub-city', 'name_am' => 'ማሳያ አራዳ ክ/ከተማ'],
        'SM-W1' => ['type' => 'WOREDA', 'name_en' => 'DEMO Arada Woreda 01', 'name_am' => 'ማሳያ አራዳ ወረዳ 01'],
        'SM-W2' => ['type' => 'WOREDA', 'name_en' => 'DEMO Arada Woreda 02', 'name_am' => 'ማሳያ አራዳ ወረዳ 02'],
    ];

    public function run(CreateOrganizationAction $createOrganization, CreateFiscalYear $createYear, RegisterParticipant $register): void
    {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);
        // Reference types as OrganizationTypeSeeder defines them; an existing type is reused, never changed.
        $types = [
            'SUB_CITY' => OrganizationType::query()->firstOrCreate(['code' => 'SUB_CITY'], ['name_en' => 'Sub City', 'name_am' => 'ክፍለ ከተማ', 'level_order' => 2, 'category' => 'geographic', 'is_active' => true]),
            'WOREDA' => OrganizationType::query()->firstOrCreate(['code' => 'WOREDA'], ['name_en' => 'Woreda', 'name_am' => 'ወረዳ', 'level_order' => 3, 'category' => 'geographic', 'is_active' => true]),
        ];
        $organizations = [];
        foreach (self::ORGANIZATIONS as $key => $definition) {
            $organizations[$key] = DemoDataset::organization($key) ?? $createOrganization->execute([
                'organization_type_id' => $types[$definition['type']]->id,
                'name_en' => $definition['name_en'],
                'name_am' => $definition['name_am'],
                'status' => OrganizationStatus::Active->value,
                'effective_from' => DemoDataset::epoch()->toDateString(),
                'metadata' => DemoDataset::tag($key, ['purpose' => 'Service delivery monitoring sample']),
            ], $maker);
        }
        $organizations['CENTER'] = DemoDataset::requireOrganization('ORG-1');

        $year = FiscalYear::query()->where('ec_year', 2019)->first() ?? $createYear->execute(2019, $maker);
        $from = $year->starts_on->toDateString();
        $participant = fn (Organization $org, ReportingLevel $level, ?Participant $parent = null): Participant => Participant::query()
            ->where('organization_id', $org->id)->whereNull('active_to')->first()
            ?? $register->execute(['organization_id' => $org->id, 'level' => $level->value, 'parent_participant_id' => $parent?->id, 'active_from' => $from], $maker);

        $subCity = $participant($organizations['SM-SUB'], ReportingLevel::SubCity);
        $w1 = $participant($organizations['SM-W1'], ReportingLevel::Woreda, $subCity);
        $w2 = $participant($organizations['SM-W2'], ReportingLevel::Woreda, $subCity);
        $center = $participant($organizations['CENTER'], ReportingLevel::Center);

        $q1 = $year->periods()->where('quarter', 1)->firstOrFail();
        $q2 = $year->periods()->where('quarter', 2)->firstOrFail();
        $this->asOf($q1, function () use ($q1, $center, $subCity, $w1, $w2, $maker): void {
            $this->report($q1, $center, $maker, 'accepted', customer: true);
            $this->report($q1, $w1, $maker, 'accepted');
            $this->report($q1, $w2, $maker, 'returned');
            $this->report($q1, $subCity, $maker, 'submitted');
        });
        $this->asOf($q2, fn () => $this->report($q2, $w1, $maker, 'draft'));
    }

    private function asOf(ReportingPeriod $period, callable $work): void
    {
        $moment = CarbonImmutable::parse($period->opens_on->toDateString().' 09:00', ReportingPeriod::TIMEZONE)->addDays(2)->utc();
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
        try {
            $work();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    private function report(ReportingPeriod $period, Participant $participant, User $actor, string $state, bool $customer = false): void
    {
        if (Submission::query()->where('period_id', $period->id)->where('participant_id', $participant->id)->exists()) {
            return;
        }
        $submission = app(StartSubmission::class)->execute($period, $participant, $actor);
        $base = $participant->level === ReportingLevel::Woreda ? 3 : 8;
        $values = [];
        foreach (FormDefinition::section2Keys() as $i => $field) {
            $values[$field] = FormDefinition::aggregationOf($field)->value === 'flow' ? $i % 3 : $base + $i;
        }
        app(SaveSection2::class)->execute($submission, $values, 'Priority queue and a ramp for customers with disabilities.', [], $actor);
        app(SaveSection4::class)->execute($submission, ['main_total' => 12, 'sub_total' => 30], [
            'main_above' => 3, 'main_at' => 8, 'main_below' => 1, 'sub_above' => 6, 'sub_at' => 24, 'sub_below' => 0,
            'below_reasons' => 'The licensing system was offline for two weeks in Nehase.',
            'actions_taken' => 'A manual register was opened and the backlog cleared within a week.',
        ], $actor);
        if ($customer) {
            app(SaveSection1Entry::class)->execute($submission, [
                'subject_type' => 'customer', 'full_name' => 'DEMO customer', 'role_category' => 'customer', 'reason_category' => 'theft_corruption',
                'reason_detail' => 'Offered money to an officer to skip the queue.', 'final_action' => 'legal_action',
                'action_detail' => 'Reported to the police; the case is with the prosecutor.', 'action_date' => $period->starts_on->addDays(20)->toDateString(),
            ], $actor);
        } else {
            app(SetNothingToReport::class)->execute($submission, 1, true, $actor);
        }
        app(SetNothingToReport::class)->execute($submission, 3, true, $actor);
        if ($state === 'draft') {
            return;
        }

        $submission = app(SubmitSubmission::class)->execute($submission->fresh(), $actor, true);
        match ($state) {
            'accepted' => app(AcceptSubmission::class)->execute($submission, DemoDataset::requireUser(DemoDataset::CHECKER_EMAIL)),
            'returned' => app(ReturnSubmission::class)->execute($submission, DemoDataset::requireUser(DemoDataset::CHECKER_EMAIL), 'The waiting-area capacity does not match the monitoring visit.'),
            default => null,
        };
    }
}
