<?php

declare(strict_types=1);

use App\Models\OrganizationType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ServiceMonitoring\Application\Actions\SaveCustomSections;
use Modules\ServiceMonitoring\Application\Services\CustomSectionSummary;
use Modules\ServiceMonitoring\Application\Services\SubmissionValidator;
use Modules\ServiceMonitoring\Domain\Enums\FormTemplateStatus;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Forms\TemplateContent;
use Modules\ServiceMonitoring\Domain\Models\FormTemplate;
use Tests\Support\ServiceMonitoringScenario;

/*
 * Form templates (docs/service-monitoring.md §Form templates): the form is
 * built in the UI, not in code. A template re-words or leaves out official
 * sections and adds its own, and is assigned to organization types instead of
 * a fixed administrative level. Without a template a report is the official
 * form, exactly as before.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = ServiceMonitoringScenario::build();
    $this->s->at(1);
    $this->woredaType = OrganizationType::query()->where('code', 'SM-WOREDA')->firstOrFail();
    $this->subCityType = OrganizationType::query()->where('code', 'SM-SUB')->firstOrFail();
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/**
 * Section III left out, Section II renamed, and one additional section: a
 * fixed table of complaints (flow) with a row total, and a staff count (stock).
 *
 * @return array<string, mixed>
 */
function tplContent(): array
{
    $content = TemplateContent::empty();
    $content['sections']['2']['title'] = 'የተቋም ግንባታ (ለወረዳ)';
    $content['sections']['3']['enabled'] = false;
    $content['custom'] = ['sections' => [[
        'key' => 'complaints',
        'title' => 'ቅሬታዎች',
        'blocks' => [
            [
                'type' => 'table', 'key' => 'complaints_table', 'title' => 'የቀረቡ ቅሬታዎች', 'row_mode' => 'fixed', 'show_totals' => true,
                'rows' => [['key' => 'service', 'label' => 'አገልግሎት'], ['key' => 'conduct', 'label' => 'ስነ-ምግባር']],
                'columns' => [
                    ['key' => 'received', 'label' => 'የቀረቡ', 'type' => 'number', 'required' => true],
                    ['key' => 'resolved', 'label' => 'የተፈቱ', 'type' => 'number'],
                    ['key' => 'total', 'label' => 'ድምር', 'type' => 'sum', 'sum_of' => ['received', 'resolved']],
                ],
            ],
            [
                'type' => 'table', 'key' => 'staff', 'title' => 'የሰው ኃይል', 'row_mode' => 'single', 'show_totals' => false,
                'columns' => [['key' => 'officers', 'label' => 'ባለሙያዎች', 'type' => 'number', 'aggregation' => 'stock']],
            ],
        ],
    ]]];

    return $content;
}

/** Builds and publishes a template for the given organization types as the coordinator. */
function tplPublished(object $test, array $typeIds, string $code = 'WOREDA-FORM'): FormTemplate
{
    $coordinator = $test->s->coordinator();
    $test->actingAs($coordinator)->post(route('service-monitoring.forms.store'), [
        'code' => $code, 'name_am' => 'የወረዳ ቅጽ', 'organization_type_ids' => $typeIds, 'content' => tplContent(),
    ])->assertSessionHasNoErrors();
    $template = FormTemplate::query()->where('code', $code)->firstOrFail();
    $test->actingAs($coordinator)->post(route('service-monitoring.forms.publish', $template))->assertSessionHasNoErrors();

    return $template->fresh();
}

it('keeps a report without a template on the official form', function (): void {
    $submission = $this->s->start('center');

    expect($submission->form_template_id)->toBeNull()
        ->and(app(SubmissionValidator::class)->completeness($submission))->toHaveKeys([1, 2, 3, 4])
        ->and(app(SubmissionValidator::class)->completeness($submission))->not->toHaveKey(SubmissionValidator::CUSTOM);
});

it('builds, publishes and versions a template; a published version is immutable', function (): void {
    $template = tplPublished($this, [$this->woredaType->id]);
    $coordinator = $this->s->coordinator();

    expect($template->status)->toBe(FormTemplateStatus::Published);

    $this->actingAs($coordinator)->put(route('service-monitoring.forms.update', $template), [
        'name_am' => 'x', 'organization_type_ids' => [$this->woredaType->id], 'content' => tplContent(),
    ])->assertForbidden();

    $this->post(route('service-monitoring.forms.new-version', $template))->assertSessionHasNoErrors();
    $draft = FormTemplate::query()->where('code', 'WOREDA-FORM')->where('version', 2)->firstOrFail();
    $this->post(route('service-monitoring.forms.new-version', $template))->assertSessionHasErrors('template');

    $this->post(route('service-monitoring.forms.publish', $draft))->assertSessionHasNoErrors();
    expect($template->fresh()->status)->toBe(FormTemplateStatus::Superseded)
        ->and($draft->fresh()->status)->toBe(FormTemplateStatus::Published);
});

it('rejects a malformed template, and one organization type gets one published template', function (): void {
    $coordinator = $this->s->coordinator();
    $bad = tplContent();
    $bad['custom']['sections'][0]['blocks'][0]['columns'][2]['sum_of'] = ['missing'];
    $this->actingAs($coordinator)->post(route('service-monitoring.forms.store'), [
        'code' => 'BAD', 'name_am' => 'x', 'organization_type_ids' => [], 'content' => $bad,
    ])->assertSessionHasErrors('content.custom.sections.0.blocks.0.columns.2.sum_of');

    tplPublished($this, [$this->woredaType->id]);
    $this->actingAs($coordinator)->post(route('service-monitoring.forms.store'), [
        'code' => 'OTHER', 'name_am' => 'ሌላ', 'organization_type_ids' => [$this->woredaType->id, $this->subCityType->id], 'content' => tplContent(),
    ])->assertSessionHasNoErrors();
    $other = FormTemplate::query()->where('code', 'OTHER')->firstOrFail();
    $this->post(route('service-monitoring.forms.publish', $other))->assertSessionHasErrors('organization_type_ids');
});

it('keeps the form builder to city-wide holders of forms.manage', function (): void {
    $this->actingAs($this->s->head('wA1'))->get(route('service-monitoring.forms.index'))->assertForbidden();
    $this->actingAs($this->s->reviewer('subA'))->get(route('service-monitoring.forms.index'))->assertForbidden();
    $this->actingAs($this->s->coordinator())->get(route('service-monitoring.forms.index'))->assertOk();
});

it('pins the template on a report of an assigned type, drops the left-out section and requires the additional answers', function (): void {
    $template = tplPublished($this, [$this->woredaType->id]);

    $center = $this->s->start('center');
    $woreda = $this->s->start('wA1');
    expect($center->form_template_id)->toBeNull()
        ->and($woreda->form_template_id)->toBe($template->id);

    // Section III is off: completing Sections I, II and IV is not enough while the additional answers are missing.
    $this->s->complete($woreda, nothing3: false);
    $problems = app(SubmissionValidator::class)->problems($woreda->fresh());
    expect($problems)->not->toHaveKey(3)
        ->and($problems)->toHaveKey(SubmissionValidator::CUSTOM);
    expect(fn () => $this->s->submit($woreda))->toThrow(ValidationException::class);

    app(SaveCustomSections::class)->execute($woreda->fresh(), [
        'complaints_table' => ['service' => ['received' => '4', 'resolved' => 1, 'total' => 999], 'conduct' => ['received' => 2]],
        'staff' => [['officers' => 7]],
    ], $this->s->head('wA1'));
    $woreda->refresh();
    expect($woreda->custom_data['complaints_table']['service']['total'])->toBe(5)
        ->and(app(SubmissionValidator::class)->problems($woreda))->toBe([]);

    $this->s->submit($woreda);
    expect($woreda->fresh()->status->value)->toBe('submitted');
});

it('consolidates the additional sections by organization type: flow summed, stock latest', function (): void {
    $template = tplPublished($this, [$this->woredaType->id]);
    $head = $this->s->head('wA1');

    foreach ([1 => [3, 10], 2 => [5, 12]] as $quarter => [$received, $officers]) {
        $this->s->at($quarter);
        $report = $this->s->complete($this->s->start('wA1', $quarter));
        app(SaveCustomSections::class)->execute($report, [
            'complaints_table' => ['service' => ['received' => $received], 'conduct' => ['received' => 0]],
            'staff' => [['officers' => $officers]],
        ], $head);
        $this->s->accept($this->s->submit($report));
    }

    $summary = app(CustomSectionSummary::class)->build($this->s->coordinator(), $template, $this->s->year, ReportRange::SixMonths, 2);
    $tables = collect($summary['tables'])->keyBy('key');
    $fixed = json_decode(json_encode($tables['complaints_table']['fixed']), true);
    $staff = json_decode(json_encode($tables['staff']['totals']), true);

    expect($summary['reports'])->toBe(2)
        ->and($fixed['service'][$this->woredaType->id]['received'])->toBe(8)
        ->and($fixed['service']['all']['total'])->toBe(8)
        ->and($staff['officers'])->toBe(12);
});

it('renders the builder pages and the entry screen with the additional sections', function (): void {
    $template = tplPublished($this, [$this->woredaType->id]);
    $coordinator = $this->s->coordinator();

    $this->actingAs($coordinator)->get(route('service-monitoring.forms.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Forms/Index'));
    $this->get(route('service-monitoring.forms.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Forms/Create'));
    $this->get(route('service-monitoring.forms.show', $template))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Forms/Show'));
    $this->get(route('service-monitoring.forms.summary', $template))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Forms/Summary'));

    $woreda = $this->s->start('wA1');
    $this->actingAs($this->s->head('wA1'))->get(route('service-monitoring.submissions.edit', $woreda))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceMonitoring/Submissions/Edit')
            ->where('definition.enabled_sections', [1, 2, 4])
            ->where('definition.section_2.title', 'የተቋም ግንባታ (ለወረዳ)')
            ->has('definition.custom.sections', 1)
            ->where('template.code', 'WOREDA-FORM'));
});
