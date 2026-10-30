<?php

declare(strict_types=1);

use App\Enums\Discipline\DisciplinaryPenalty;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryDecision;
use App\Models\Occupation;
use App\Models\Position;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Actions\SaveSection1Entry;
use Modules\ServiceMonitoring\Application\Actions\SaveSection3Entry;
use Modules\ServiceMonitoring\Application\Actions\SetNothingToReport;
use Modules\ServiceMonitoring\Application\Notifications\DigitalServiceRejectedNotification;
use Modules\ServiceMonitoring\Application\Services\AccountabilitySuggestionService;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\SubmissionValidator;
use Modules\ServiceMonitoring\Domain\Enums\FormType;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Domain\Models\DriverOccupation;
use Modules\ServiceMonitoring\Domain\Models\PrefillValue;
use Tests\Support\ServiceMonitoringScenario;

/*
 * Sections I–IV (phase 3): identity, final action, duplicates and
 * encryption in Section I (D12–D15), discipline suggestions (D16), the
 * Section II prefill (D18, D19), Section III (D20) and Section IV (D21).
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = ServiceMonitoringScenario::build();
    $this->s->at(1);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> */
function smEntry(array $overrides = []): array
{
    return [
        'subject_type' => 'customer', 'full_name' => 'A customer', 'role_category' => 'customer', 'reason_category' => 'theft_corruption',
        'reason_detail' => 'Offered money to skip the queue.', 'final_action' => 'legal_action', 'action_detail' => 'Handed to the police.',
        'action_date' => '2026-08-15', 'office_name' => 'Land office', ...$overrides,
    ];
}

it('enforces the Section I identity, office, date and duplicate rules', function (): void {
    $submission = $this->s->start('wA1');
    $head = $this->s->head('wA1');
    $url = route('service-monitoring.submissions.section1.store', $submission);
    $employee = $this->s->employee('wA1');
    $outsider = $this->s->employee('wA2', 'Outside Person');

    // Employee rows name an employee of the institution, never the customer role.
    $this->actingAs($head)->post($url, smEntry(['subject_type' => 'employee', 'role_category' => 'professional']))->assertSessionHasErrors('employee_id');
    $this->actingAs($head)->post($url, smEntry(['subject_type' => 'employee', 'employee_id' => $outsider->id, 'role_category' => 'professional']))->assertSessionHasErrors('employee_id');
    $this->actingAs($head)->post($url, smEntry(['subject_type' => 'employee', 'employee_id' => $employee->id, 'role_category' => 'customer']))->assertSessionHasErrors('role_category');
    // A customer row uses the customer role only.
    $this->actingAs($head)->post($url, smEntry(['role_category' => 'professional']))->assertSessionHasErrors('role_category');
    // A final action is required; "in progress" does not exist.
    $this->actingAs($head)->post($url, smEntry(['final_action' => 'in_progress']))->assertSessionHasErrors('final_action');
    // Woreda rows name the office; the action date lies in the quarter.
    $this->actingAs($head)->post($url, smEntry(['office_name' => '']))->assertSessionHasErrors('office_name');
    $this->actingAs($head)->post($url, smEntry(['action_date' => '2026-10-15']))->assertSessionHasErrors('action_date');

    $this->actingAs($head)->post($url, smEntry(['subject_type' => 'employee', 'employee_id' => $employee->id, 'role_category' => 'professional', 'final_action' => 'salary_fine', 'full_name' => 'Ignored']))->assertSessionHasNoErrors();
    $entry = AccountabilityEntry::query()->sole();
    expect($entry->full_name)->toBe('Abebe Kebede')
        ->and($entry->sequence)->toBe(1);

    // One row per employee per submission.
    $this->actingAs($head)->post($url, smEntry(['subject_type' => 'employee', 'employee_id' => $employee->id, 'role_category' => 'professional', 'final_action' => 'dismissal']))->assertSessionHasErrors('employee_id');

    // Center rows need no office.
    $center = $this->s->start('center');
    $this->actingAs($this->s->head('center'))->post(route('service-monitoring.submissions.section1.store', $center), smEntry(['office_name' => '']))->assertSessionHasNoErrors();
});

it('stores names, reasons and actions encrypted at rest', function (): void {
    $submission = $this->s->start('wA1');
    app(SaveSection1Entry::class)->execute($submission, smEntry(['full_name' => 'Plaintext Customer Name']), $this->s->head('wA1'));

    $raw = DB::table('service_monitoring_accountability_entries')->first();
    expect($raw->full_name)->not->toContain('Plaintext')
        ->and($raw->reason_detail)->not->toContain('queue')
        ->and($raw->action_detail)->not->toContain('police')
        ->and(AccountabilityEntry::query()->first()->full_name)->toBe('Plaintext Customer Name');
});

it('warns about an employee reported in an earlier accepted quarter and needs a confirmation', function (): void {
    $employee = $this->s->employee('wA1');
    $q1 = $this->s->start('wA1');
    app(SaveSection1Entry::class)->execute($q1, smEntry(['subject_type' => 'employee', 'employee_id' => $employee->id, 'role_category' => 'professional', 'final_action' => 'warning']), $this->s->head('wA1'));
    $this->s->complete($q1, nothing1: false);
    $this->s->accept($this->s->submit($q1));

    $this->s->at(2);
    $q2 = $this->s->start('wA1', 2);
    $data = smEntry(['subject_type' => 'employee', 'employee_id' => $employee->id, 'role_category' => 'professional', 'final_action' => 'dismissal', 'action_date' => '2026-11-20']);
    expect(fn () => app(SaveSection1Entry::class)->execute($q2, $data, $this->s->head('wA1')))->toThrow(ValidationException::class);
    app(SaveSection1Entry::class)->execute($q2, [...$data, 'repeat_confirmed' => true], $this->s->head('wA1'));
    expect(app(SubmissionValidator::class)->repeatEmployeeIds($q2->fresh())->all())->toBe([$employee->id]);

    // The six-month report counts the employee once, by the latest action.
    $this->s->complete($q2, nothing1: false);
    $this->s->accept($this->s->submit($q2));
    $report = app(ConsolidationService::class)->report($this->s->year, ReportRange::SixMonths, 2, null, false);
    expect($report['summary']['grand_total'])->toBe(1)
        ->and($report['summary']['matrix']['woreda']['dismissal']['professional'])->toBe(1)
        ->and($report['summary']['matrix']['woreda']['warning']['professional'])->toBe(0);
});

it('derives I.2 by final action, role and level, with totals', function (): void {
    $wA1 = $this->s->start('wA1');
    $sub = $this->s->start('subA');
    $center = $this->s->start('center');
    app(SaveSection1Entry::class)->execute($wA1, smEntry(), $this->s->head('wA1'));
    app(SaveSection1Entry::class)->execute($wA1, smEntry(['final_action' => 'other']), $this->s->head('wA1'));
    app(SaveSection1Entry::class)->execute($sub, smEntry(['subject_type' => 'employee', 'employee_id' => $this->s->employee('subA')->id, 'role_category' => 'merit_leader', 'final_action' => 'demotion']), $this->s->head('subA'));
    app(SaveSection1Entry::class)->execute($center, smEntry(['subject_type' => 'employee', 'employee_id' => $this->s->employee('center')->id, 'role_category' => 'appointee_leader', 'final_action' => 'removal_from_responsibility']), $this->s->head('center'));
    foreach ([$wA1, $sub, $center] as $submission) {
        $this->s->accept($this->s->submit($this->s->complete($submission, nothing1: false)));
    }

    $summary = app(ConsolidationService::class)->report($this->s->year, ReportRange::Quarter, 1, null, false)['summary'];
    expect($summary['matrix']['woreda']['legal_action']['customer'])->toBe(1)
        ->and($summary['matrix']['woreda']['other']['customer'])->toBe(1)
        ->and($summary['matrix']['sub_city']['demotion']['merit_leader'])->toBe(1)
        ->and($summary['matrix']['center']['removal_from_responsibility']['appointee_leader'])->toBe(1)
        ->and($summary['level_totals'])->toBe(['center' => 1, 'sub_city' => 1, 'woreda' => 2])
        ->and($summary['row_totals']['woreda']['legal_action'])->toBe(1)
        ->and($summary['column_totals']['woreda']['customer'])->toBe(2)
        ->and($summary['grand_total'])->toBe(4);
});

it('suggests final disciplinary decisions only, maps penalties, stays read-only and refuses a second acceptance', function (): void {
    $employee = $this->s->employee('wA1');
    $position = Position::query()->create(['organization_id' => $this->s->org['wA1']->id, 'job_position_code' => 'SM-P1', 'title_en' => 'Head', 'is_active' => true, 'is_unit_head' => true]);
    $case = fn (string $status): DisciplinaryCase => DisciplinaryCase::query()->forceCreate([
        'accused_employee_id' => $employee->id, 'organization_id' => $this->s->org['wA1']->id, 'position_id' => $position->id,
        'track' => 'expedited', 'severity' => 'minor', 'status' => $status,
    ]);
    $decision = fn (DisciplinaryCase $c, string $penalty, ?string $delivered, string $action = 'approve'): DisciplinaryDecision => DisciplinaryDecision::query()->forceCreate([
        'case_id' => $c->id, 'action' => $action, 'penalty' => $penalty, 'reason' => 'Proven late service.', 'decided_at' => '2026-08-01 10:00:00', 'delivered_at' => $delivered,
    ]);
    $final = $decision($case('decision_delivered'), 'salary_fine_days', '2026-08-05 08:00:00');
    $decision($case('decided'), 'dismissal', null);                           // not delivered
    $decision($case('decision_delivered'), 'written_warning', '2026-06-01 08:00:00'); // before the quarter
    $decision($case('decision_delivered'), 'demotion', '2026-08-06 08:00:00', 'reinvestigate'); // not a penalty decision

    $other = $this->s->employee('wA2', 'Other Woreda');
    $otherCase = DisciplinaryCase::query()->forceCreate(['accused_employee_id' => $other->id, 'organization_id' => $this->s->org['wA2']->id, 'track' => 'expedited', 'severity' => 'minor', 'status' => 'closed']);
    $decision($otherCase, 'dismissal', '2026-08-07 08:00:00');

    $submission = $this->s->start('wA1');
    $head = $this->s->head('wA1');
    $before = [DB::table('disciplinary_cases')->orderBy('id')->get()->toJson(), DB::table('disciplinary_decisions')->orderBy('id')->get()->toJson()];

    $suggestions = app(AccountabilitySuggestionService::class)->suggestionsFor($submission, $head);
    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['decision_id'])->toBe($final->id)
        ->and($suggestions[0]['final_action'])->toBe('salary_fine')
        ->and($suggestions[0]['role_category'])->toBe('merit_leader')
        ->and(AccountabilitySuggestionService::finalActionFor(DisciplinaryPenalty::OralWarning)->value)->toBe('warning')
        ->and(AccountabilitySuggestionService::finalActionFor(DisciplinaryPenalty::SalaryFineMonths)->value)->toBe('salary_fine')
        ->and(AccountabilitySuggestionService::finalActionFor(DisciplinaryPenalty::Demotion)->value)->toBe('demotion')
        ->and(AccountabilitySuggestionService::finalActionFor(DisciplinaryPenalty::Dismissal)->value)->toBe('dismissal');

    $accept = ['decision_id' => $final->id, 'role_category' => 'merit_leader', 'reason_category' => 'service_delay', 'reason_detail' => 'Delayed licences for weeks.', 'office_name' => 'Trade office'];
    $this->actingAs($head)->post(route('service-monitoring.submissions.section1.suggestion', $submission), $accept)->assertSessionHasNoErrors();
    $entry = AccountabilityEntry::query()->sole();
    expect($entry->source->value)->toBe('discipline')
        ->and($entry->source_decision_id)->toBe($final->id)
        ->and($entry->final_action->value)->toBe('salary_fine')
        ->and($entry->action_date->toDateString())->toBe('2026-08-05');

    $this->actingAs($head)->post(route('service-monitoring.submissions.section1.suggestion', $submission), $accept)->assertSessionHasErrors('decision_id');
    // A decision of another woreda is not available here.
    $this->actingAs($head)->post(route('service-monitoring.submissions.section1.suggestion', $submission), [...$accept, 'decision_id' => DisciplinaryDecision::query()->where('case_id', $otherCase->id)->value('id')])->assertSessionHasErrors('decision_id');

    expect([DB::table('disciplinary_cases')->orderBy('id')->get()->toJson(), DB::table('disciplinary_decisions')->orderBy('id')->get()->toJson()])->toBe($before);
});

it('prefills employees and chest badges without drivers, and needs a reason to differ', function (): void {
    $driver = Occupation::query()->create(['code' => 'SM-DRV', 'name_en' => 'Driver', 'is_active' => true]);
    $clerk = Occupation::query()->create(['code' => 'SM-CLK', 'name_en' => 'Clerk', 'is_active' => true]);
    $drivingPost = Position::query()->create(['organization_id' => $this->s->org['wA1']->id, 'job_position_code' => 'SM-D1', 'title_en' => 'Driver', 'occupation_id' => $driver->id, 'is_active' => true]);
    $clerkPost = Position::query()->create(['organization_id' => $this->s->org['wA1']->id, 'job_position_code' => 'SM-C1', 'title_en' => 'Clerk', 'occupation_id' => $clerk->id, 'is_active' => true]);
    foreach ([[$drivingPost, 'Driver One'], [$clerkPost, 'Clerk One'], [$clerkPost, 'Clerk Two']] as [$post, $name]) {
        $employee = $this->s->employee('wA1', $name);
        $employee->currentAssignment->update(['position_id' => $post->id]);
    }
    // A woreda-level employee elsewhere never counts.
    $this->s->employee('wA2', 'Other Woreda');

    // Empty driver list: no prefill, a visible notice.
    $empty = $this->s->start('wA2');
    expect(PrefillValue::query()->where('submission_id', $empty->id)->where('field', 'employees_excl_drivers')->first())
        ->system_value->toBeNull()->notice->toBe('driver_list_empty');

    DriverOccupation::query()->create(['occupation_id' => $driver->id]);
    $submission = $this->s->start('wA1');
    $prefill = PrefillValue::query()->where('submission_id', $submission->id)->pluck('system_value', 'field');
    expect($prefill['employees_excl_drivers'])->toBe(2)
        ->and($prefill['chest_badges_excl_drivers'])->toBe(0)
        ->and($prefill['cafeteria_count'])->toBe(0)
        ->and($submission->institutionBuilding->employees_excl_drivers)->toBe(2);

    $head = $this->s->head('wA1');
    $values = collect(FormDefinition::section2Keys())->mapWithKeys(fn ($f) => [$f => 1])->all();
    $url = route('service-monitoring.submissions.section2', $submission);
    $this->actingAs($head)->put($url, ['values' => [...$values, 'employees_excl_drivers' => 5, 'chest_badges_excl_drivers' => 0]])->assertSessionHasErrors('override_reasons.employees_excl_drivers');
    $this->actingAs($head)->put($url, ['values' => [...$values, 'employees_excl_drivers' => 5, 'chest_badges_excl_drivers' => 0], 'override_reasons' => ['employees_excl_drivers' => 'short']])->assertSessionHasErrors('override_reasons.employees_excl_drivers');
    $this->actingAs($head)->put($url, ['values' => [...$values, 'employees_excl_drivers' => 5, 'chest_badges_excl_drivers' => 0], 'override_reasons' => ['employees_excl_drivers' => 'Three contract staff are not in the system yet.']])->assertSessionHasNoErrors();
    // The cafeteria figure is a reference only: no reason needed.
    $this->actingAs($head)->put($url, ['values' => [...$values, 'employees_excl_drivers' => 2, 'chest_badges_excl_drivers' => 0, 'cafeteria_count' => 4]])->assertSessionHasNoErrors();
});

it('shows the "newly organized" rule on Form B only', function (): void {
    $b = FormDefinition::describe(FormType::B, 'fy2019', 2019, 'am');
    $a = FormDefinition::describe(FormType::A, 'fy2019', 2019, 'am');
    $flag = fn (array $d, string $key): bool => collect($d['section_2']['fields'])->firstWhere('key', $key)['newly_organized'];

    expect($flag($b, 'cafeteria_count'))->toBeTrue()->and($flag($b, 'daycare_count'))->toBeTrue()->and($flag($b, 'led_count'))->toBeFalse()
        ->and($flag($a, 'cafeteria_count'))->toBeFalse()
        ->and($b['section_2']['notes'][0])->toContain('2019')
        ->and($a['section_2']['notes'])->toBe([])
        ->and($a['section_1']['summary']['role_columns'][0]['label'])->toBe('ሹመኛ/ሜሪት+ሹመት/')
        ->and($b['section_1']['summary']['role_columns'][0]['label'])->toBe('ሹመኛ አመራር')
        ->and($b['section_1']['tables'][1]['remark_column'])->toBe('ምርመራ/ጽ/ቤት ይጠቀስ/');
});

it('links Section III rows to the catalog or flags a gap, keeps the main → sub tree and the fiscal year rule', function (): void {
    $submission = $this->s->start('center');
    $head = $this->s->head('center');
    $service = Service::query()->create(['organization_id' => $this->s->org['center']->id, 'lineage_key' => (string) Str::uuid7(), 'version_no' => 1, 'code' => 'SRV-1', 'name_en' => 'Trade licence', 'name_am' => 'የንግድ ፈቃድ', 'status' => 'published', 'effective_from' => '2026-07-08']);
    $foreign = Service::query()->create(['organization_id' => $this->s->org['wA1']->id, 'lineage_key' => (string) Str::uuid7(), 'version_no' => 1, 'code' => 'SRV-2', 'name_en' => 'Other', 'status' => 'published', 'effective_from' => '2026-07-08']);
    $url = route('service-monitoring.submissions.section3.store', $submission);
    $row = ['level' => 'main', 'technology_type' => 'web_system', 'system_name' => 'e-Trade', 'system_count' => 1, 'go_live_on' => '2026-08-01'];

    $this->actingAs($head)->post($url, [...$row, 'service_id' => $foreign->id])->assertSessionHasErrors('service_id');
    $this->actingAs($head)->post($url, [...$row, 'service_id' => $service->id, 'go_live_on' => '2025-12-01'])->assertSessionHasErrors('go_live_on');
    $this->actingAs($head)->post($url, [...$row, 'service_id' => $service->id])->assertSessionHasNoErrors();
    $main = DigitalService::query()->sole();
    expect($main->is_catalog_gap)->toBeFalse()->and($main->service_name_am)->toBe('የንግድ ፈቃድ');

    $this->actingAs($head)->post($url, [...$row, 'level' => 'sub'])->assertSessionHasErrors('parent_entry_id');
    $this->actingAs($head)->post($url, [...$row, 'level' => 'sub', 'parent_entry_id' => $main->id, 'service_name_am' => 'የፈቃድ እድሳት', 'system_name' => 'e-Renewal'])->assertSessionHasNoErrors();
    $sub = DigitalService::query()->where('level', 'sub')->sole();
    expect($sub->is_catalog_gap)->toBeTrue()->and($sub->parent_entry_id)->toBe($main->id);
});

it('counts only verified digital services, and notifies the institution of a rejection', function (): void {
    $submission = $this->s->start('center');
    $head = $this->s->head('center');
    app(SaveSection3Entry::class)->execute($submission, ['level' => 'main', 'service_name_am' => 'ሀ', 'technology_type' => 'web_system', 'system_name' => 'A', 'system_count' => 2, 'go_live_on' => '2026-08-01'], $head);
    app(SaveSection3Entry::class)->execute($submission, ['level' => 'main', 'service_name_am' => 'ለ', 'technology_type' => 'mobile_app', 'system_name' => 'B', 'system_count' => 1, 'go_live_on' => '2026-08-02'], $head);
    $this->s->complete($submission, nothing3: false);

    $verifier = $this->s->verifier();
    [$first, $second] = DigitalService::query()->orderBy('sequence')->get()->all();
    // Not on a draft.
    $this->actingAs($verifier)->post(route('service-monitoring.verification.verify', $first))->assertSessionHasErrors('verification');

    $submitted = $this->s->submit($submission);
    $this->actingAs($this->s->head('center'))->post(route('service-monitoring.verification.verify', $first))->assertForbidden();
    $this->actingAs($verifier)->post(route('service-monitoring.verification.verify', $first))->assertSessionHasNoErrors();
    $this->actingAs($verifier)->post(route('service-monitoring.verification.reject', $second), ['reason' => 'Not in operation yet.'])->assertSessionHasNoErrors();
    expect($submitted->fresh()->status->value)->toBe('submitted');
    Notification::assertSentTo($head, DigitalServiceRejectedNotification::class);

    $this->s->accept($submitted);
    $row = app(ConsolidationService::class)->report($this->s->year, ReportRange::Quarter, 1, null, false)['rows'][0];
    expect($row['digital'])->toBe(['main' => 1, 'sub' => 0, 'systems' => 2]);
});

it('checks the Section IV sums for main and sub services and needs reasons below standard', function (): void {
    expect(SubmissionValidator::section4Problems(['main_above' => 1, 'main_at' => 2, 'main_below' => 0, 'sub_above' => 0, 'sub_at' => 5, 'sub_below' => 0], 3, 5))->toBe([])
        ->and(SubmissionValidator::section4Problems(['main_above' => 1, 'main_at' => 1, 'main_below' => 0, 'sub_above' => 0, 'sub_at' => 5, 'sub_below' => 0], 3, 5))->toHaveCount(1)
        ->and(SubmissionValidator::section4Problems(['main_above' => 1, 'main_at' => 2, 'main_below' => 0, 'sub_above' => 0, 'sub_at' => 4, 'sub_below' => 0], 3, 5))->toHaveCount(1)
        ->and(SubmissionValidator::section4Problems(['main_above' => 0, 'main_at' => 2, 'main_below' => 1, 'sub_above' => 0, 'sub_at' => 5, 'sub_below' => 0, 'below_reasons' => 'short', 'actions_taken' => 'short'], 3, 5))->toHaveCount(1)
        ->and(SubmissionValidator::section4Problems(['main_above' => 0, 'main_at' => 2, 'main_below' => 1, 'sub_above' => 0, 'sub_at' => 5, 'sub_below' => 0,
            'below_reasons' => 'The system was down for two weeks.', 'actions_taken' => 'A backup line was installed at once.'], 3, 5))->toBe([]);

    $submission = $this->s->complete($this->s->start('wA1'), section4: ['main_above' => 0, 'main_at' => 5, 'main_below' => 5]);
    expect(fn () => $this->s->submit($submission))->toThrow(ValidationException::class);
});

it('refuses rows in a section marked "የለም"', function (): void {
    $submission = $this->s->start('wA1');
    app(SetNothingToReport::class)->execute($submission, 1, true, $this->s->head('wA1'));
    expect(fn () => app(SaveSection1Entry::class)->execute($submission->fresh(), smEntry(), $this->s->head('wA1')))->toThrow(ValidationException::class);
});
