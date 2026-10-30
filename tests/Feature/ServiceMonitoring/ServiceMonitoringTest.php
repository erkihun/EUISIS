<?php

declare(strict_types=1);

use App\Enums\ServiceMonitoring\FormStatus;
use App\Enums\ServiceMonitoring\SubmissionStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\ServiceMonitoringForm;
use App\Models\ServiceMonitoringPeriod;
use App\Models\ServiceMonitoringSubmission;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceMonitoring\ServiceMonitoringSummaryService;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use App\Support\ServiceMonitoring\FormSchema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Service Delivery Monitoring (docs/service-monitoring.md)
|--------------------------------------------------------------------------
| A bureau builds versioned forms and assigns them to organization types;
| institutions of those types fill one report per form and period; the
| consolidated report groups institutions by type, never by a fixed
| "sub-city / city" level.
*/

/** @param list<string> $permissions */
function smUser(string $role, array $permissions, bool $global): User
{
    $roleModel = Role::findOrCreate($role, 'web');
    $roleModel->forceFill(['scope_type' => $global ? 'global' : 'scoped'])->save();
    foreach ($permissions as $permission) {
        $roleModel->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($roleModel);
    $user->forgetCachedPermissions();

    return $user->fresh();
}

/** A small form: a list of people with a role mark, and fixed action rows with a row total. */
function smSchema(): array
{
    return ['sections' => [[
        'key' => 'accountability',
        'title' => 'ክፍል አንድ',
        'blocks' => [
            [
                'type' => 'table', 'key' => 'people', 'title' => 'ተጠያቂ የተደረጉ', 'row_mode' => 'dynamic', 'show_totals' => true,
                'column_groups' => [['key' => 'role', 'label' => 'የስራ ድርሻ']],
                'columns' => [
                    ['key' => 'full_name', 'label' => 'ሙሉ ስም', 'type' => 'text', 'required' => true],
                    ['key' => 'staff', 'label' => 'ባለሙያ', 'type' => 'check', 'group' => 'role'],
                    ['key' => 'client', 'label' => 'ተገልጋይ', 'type' => 'check', 'group' => 'role'],
                ],
            ],
            [
                'type' => 'table', 'key' => 'counts', 'title' => 'የቁጥር ማጠቃለያ', 'row_mode' => 'fixed', 'show_totals' => true,
                'rows' => [['key' => 'warning', 'label' => 'ማስጠንቀቂያ'], ['key' => 'dismissal', 'label' => 'ስንብት']],
                'columns' => [
                    ['key' => 'staff', 'label' => 'ባለሙያ', 'type' => 'number'],
                    ['key' => 'client', 'label' => 'ተገልጋይ', 'type' => 'number'],
                    ['key' => 'total', 'label' => 'ድምር', 'type' => 'sum', 'sum_of' => ['staff', 'client']],
                ],
            ],
            ['type' => 'text', 'key' => 'special_support', 'label' => 'ልዩ ድጋፍ', 'required' => false],
        ],
    ]]];
}

beforeEach(function (): void {
    $this->subCity = OrganizationType::query()->create(['code' => 'SM-SUBCITY', 'name_en' => 'Sub-city', 'name_am' => 'ክፍለ ከተማ', 'is_active' => true]);
    $this->woreda = OrganizationType::query()->create(['code' => 'SM-WOREDA', 'name_en' => 'Woreda', 'name_am' => 'ወረዳ', 'is_active' => true]);
    $this->agency = OrganizationType::query()->create(['code' => 'SM-AGENCY', 'name_en' => 'Agency', 'name_am' => 'ኤጀንሲ', 'is_active' => true]);

    $this->bole = Organization::query()->create(['organization_type_id' => $this->subCity->id, 'code' => 'SM-BOLE', 'name_en' => 'Bole', 'name_am' => 'ቦሌ', 'status' => 'active']);
    $this->woreda1 = Organization::query()->create(['organization_type_id' => $this->woreda->id, 'code' => 'SM-W1', 'name_en' => 'Woreda 1', 'name_am' => 'ወረዳ 1', 'status' => 'active']);
    $this->agencyOrg = Organization::query()->create(['organization_type_id' => $this->agency->id, 'code' => 'SM-AG', 'name_en' => 'Some Agency', 'name_am' => 'ኤጀንሲ', 'status' => 'active']);

    $all = PermissionCatalog::matching('service_monitoring.');
    $this->bureau = smUser('SM Bureau', $all, global: true);
    $this->reviewer = smUser('SM Reviewer', ['service_monitoring.reports.review', 'service_monitoring.reports.view'], global: true);
    $this->institution = smUser('SM Institution', ['service_monitoring.reports.submit', 'service_monitoring.reports.view'], global: false);
    UserOrganizationScope::query()->create(['user_id' => $this->institution->id, 'organization_id' => $this->bole->id, 'scope_type' => 'self', 'is_active' => true]);
    app(OrganizationScopeService::class)->clearCache();

    $this->period = ServiceMonitoringPeriod::query()->create(['fiscal_year' => 2019, 'period_type' => 'first_quarter', 'name_am' => 'የ2019 የመጀመሪያ ሩብ ዓመት', 'status' => 'open']);
});

/** Create, assign to sub-cities and woredas, and publish a form as the bureau. */
function smPublishedForm(string $code = 'SM-FORM'): ServiceMonitoringForm
{
    test()->actingAs(test()->bureau)->post(route('service-monitoring.forms.store'), [
        'code' => $code,
        'name_am' => 'የክትትል ቅጽ',
        'organization_type_ids' => [test()->subCity->id, test()->woreda->id],
        'schema' => smSchema(),
    ])->assertSessionHasNoErrors();
    $form = ServiceMonitoringForm::query()->where('code', $code)->firstOrFail();
    test()->post(route('service-monitoring.forms.publish', $form))->assertSessionHasNoErrors();

    return $form->fresh();
}

it('registers its permissions in the catalog and the default roles without problems', function (): void {
    expect(PermissionCatalog::matching('service_monitoring.'))->toHaveCount(5)
        ->and(PermissionCatalog::problems())->toBe([])
        ->and(DefaultRoleMatrix::problems())->toBe([]);
});

it('seeds the Word templates as one generic draft without sub-city or woreda rows', function (): void {
    $form = ServiceMonitoringForm::query()->where('code', 'SERVICE-DELIVERY-QUARTERLY')->firstOrFail();
    $json = json_encode($form->schema, JSON_UNESCAPED_UNICODE);

    expect($form->status)->toBe(FormStatus::Draft)
        ->and(FormSchema::from($form->schema)->problems(forPublish: true))->toBe([])
        ->and($json)->not->toContain('ክ/ከተማ')
        ->and($json)->not->toContain('ወረዳ')
        ->and($json)->not->toContain('2019');
});

it('builds, publishes and versions a form; a published version is immutable', function (): void {
    $form = smPublishedForm();
    expect($form->status)->toBe(FormStatus::Published)
        ->and($form->organizationTypes()->pluck('organization_types.id')->all())->toEqualCanonicalizing([$this->subCity->id, $this->woreda->id]);

    $this->put(route('service-monitoring.forms.update', $form), ['name_am' => 'x', 'organization_type_ids' => [], 'schema' => smSchema()])->assertForbidden();

    $this->post(route('service-monitoring.forms.new-version', $form))->assertSessionHasNoErrors();
    $draft = ServiceMonitoringForm::query()->where('code', 'SM-FORM')->where('version', 2)->firstOrFail();
    expect($draft->status)->toBe(FormStatus::Draft);

    // One draft per form.
    $this->post(route('service-monitoring.forms.new-version', $form))->assertSessionHasErrors('form');

    $this->post(route('service-monitoring.forms.publish', $draft))->assertSessionHasNoErrors();
    expect($form->fresh()->status)->toBe(FormStatus::Superseded)
        ->and($draft->fresh()->status)->toBe(FormStatus::Published);
});

it('rejects a malformed schema and refuses to publish a form assigned to no organization type', function (): void {
    $bad = smSchema();
    $bad['sections'][0]['blocks'][1]['columns'][2]['sum_of'] = ['missing'];

    $this->actingAs($this->bureau)->post(route('service-monitoring.forms.store'), [
        'code' => 'SM-BAD', 'name_am' => 'ቅጽ', 'organization_type_ids' => [], 'schema' => $bad,
    ])->assertSessionHasErrors('schema.sections.0.blocks.1.columns.2.sum_of');

    $this->post(route('service-monitoring.forms.store'), [
        'code' => 'SM-NOTYPES', 'name_am' => 'ቅጽ', 'organization_type_ids' => [], 'schema' => smSchema(),
    ])->assertSessionHasNoErrors();
    $form = ServiceMonitoringForm::query()->where('code', 'SM-NOTYPES')->firstOrFail();
    $this->post(route('service-monitoring.forms.publish', $form))->assertSessionHasErrors('organization_type_ids');
});

it('keeps the form builder to the bureau', function (): void {
    $this->actingAs($this->institution)->get(route('service-monitoring.forms.index'))->assertForbidden();
    $this->actingAs($this->institution)->post(route('service-monitoring.forms.store'), [
        'code' => 'SM-X', 'name_am' => 'x', 'organization_type_ids' => [], 'schema' => smSchema(),
    ])->assertForbidden();
});

it('lets an institution report only for itself, on a form assigned to its type', function (): void {
    $form = smPublishedForm();

    $this->actingAs($this->institution)->post(route('service-monitoring.reports.start'), [
        'form_id' => $form->id, 'period_id' => $this->period->id, 'organization_id' => $this->woreda1->id,
    ])->assertSessionHasErrors('organization_id');

    UserOrganizationScope::query()->create(['user_id' => $this->institution->id, 'organization_id' => $this->agencyOrg->id, 'scope_type' => 'self', 'is_active' => true]);
    app(OrganizationScopeService::class)->clearCache();
    $this->actingAs($this->institution->fresh())->post(route('service-monitoring.reports.start'), [
        'form_id' => $form->id, 'period_id' => $this->period->id, 'organization_id' => $this->agencyOrg->id,
    ])->assertSessionHasErrors('organization_id');

    $this->actingAs($this->institution)->post(route('service-monitoring.reports.start'), [
        'form_id' => $form->id, 'period_id' => $this->period->id, 'organization_id' => $this->bole->id,
    ])->assertRedirect();

    // Reopening returns the same report.
    $this->post(route('service-monitoring.reports.start'), [
        'form_id' => $form->id, 'period_id' => $this->period->id, 'organization_id' => $this->bole->id,
    ]);
    expect(ServiceMonitoringSubmission::query()->count())->toBe(1);
});

it('saves drafts, recomputes totals, enforces required answers on submit and locks after submission', function (): void {
    $form = smPublishedForm();
    $this->actingAs($this->institution)->post(route('service-monitoring.reports.start'), [
        'form_id' => $form->id, 'period_id' => $this->period->id, 'organization_id' => $this->bole->id,
    ]);
    $report = ServiceMonitoringSubmission::query()->firstOrFail();

    $data = [
        'people' => [['full_name' => '', 'staff' => true], ['full_name' => '', 'staff' => false, 'client' => false]],
        'counts' => ['warning' => ['staff' => '2', 'client' => 1, 'total' => 999]],
    ];
    $this->post(route('service-monitoring.reports.update', $report), ['data' => $data, 'submit' => false])->assertSessionHasNoErrors();
    $report->refresh();
    expect($report->status)->toBe(SubmissionStatus::Draft)
        ->and($report->data['people'])->toHaveCount(1)
        ->and($report->data['counts']['warning']['total'])->toBe(3);

    $this->post(route('service-monitoring.reports.update', $report), ['data' => $data, 'submit' => true])
        ->assertSessionHasErrors('data.people.0.full_name');

    $data['people'][0]['full_name'] = 'Abebe Kebede';
    $this->post(route('service-monitoring.reports.update', $report), ['data' => $data, 'submit' => true])->assertSessionHasNoErrors();
    expect($report->fresh()->status)->toBe(SubmissionStatus::Submitted);

    $this->post(route('service-monitoring.reports.update', $report), ['data' => $data, 'submit' => false])->assertForbidden();
});

it('reviews a report: maker is never checker, a return reopens it, an accept closes it', function (): void {
    $form = smPublishedForm();
    $this->actingAs($this->institution)->post(route('service-monitoring.reports.start'), [
        'form_id' => $form->id, 'period_id' => $this->period->id, 'organization_id' => $this->bole->id,
    ]);
    $report = ServiceMonitoringSubmission::query()->firstOrFail();
    $data = ['people' => [['full_name' => 'Abebe', 'staff' => true]], 'counts' => []];
    $this->post(route('service-monitoring.reports.update', $report), ['data' => $data, 'submit' => true])->assertSessionHasNoErrors();

    // The submitter holds no review permission.
    $this->post(route('service-monitoring.reports.review', $report), ['decision' => 'accept'])->assertForbidden();

    $this->actingAs($this->reviewer)->post(route('service-monitoring.reports.review', $report), ['decision' => 'return'])->assertSessionHasErrors('note');
    $this->post(route('service-monitoring.reports.review', $report), ['decision' => 'return', 'note' => 'Fill the counts'])->assertSessionHasNoErrors();
    expect($report->fresh()->status)->toBe(SubmissionStatus::Returned);

    $this->actingAs($this->institution)->post(route('service-monitoring.reports.update', $report), ['data' => $data, 'submit' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->reviewer)->post(route('service-monitoring.reports.review', $report), ['decision' => 'accept'])->assertSessionHasNoErrors();
    expect($report->fresh()->status)->toBe(SubmissionStatus::Accepted);
});

it('consolidates reports by organization type and names the institutions that have not reported', function (): void {
    $form = smPublishedForm();
    foreach ([[$this->bole, 2], [$this->woreda1, 5]] as [$organization, $warnings]) {
        ServiceMonitoringSubmission::query()->create([
            'form_id' => $form->id, 'form_code' => $form->code, 'period_id' => $this->period->id, 'organization_id' => $organization->id,
            'status' => SubmissionStatus::Submitted,
            'data' => FormSchema::from($form->schema)->normalize([
                'people' => [['full_name' => "Person {$warnings}", 'client' => true]],
                'counts' => ['warning' => ['staff' => $warnings]],
            ], complete: false)['data'],
        ]);
    }
    $woreda2 = Organization::query()->create(['organization_type_id' => $this->woreda->id, 'code' => 'SM-W2', 'name_en' => 'Woreda 2', 'name_am' => 'ወረዳ 2', 'status' => 'active']);

    $summary = app(ServiceMonitoringSummaryService::class)->build($this->bureau, $form, $this->period, null, false);
    $counts = collect($summary['tables'])->firstWhere('key', 'counts');
    $people = collect($summary['tables'])->firstWhere('key', 'people');
    $fixed = json_decode(json_encode($counts['fixed']), true);

    expect($fixed['warning'][$this->subCity->id]['staff'])->toBe(2)
        ->and($fixed['warning'][$this->woreda->id]['staff'])->toBe(5)
        ->and($fixed['warning']['all']['total'])->toBe(7)
        ->and($people['listed_count'])->toBe(2)
        ->and($summary['coverage']['expected'])->toBe(3)
        ->and($summary['coverage']['reported'])->toBe(2)
        ->and(array_column($summary['coverage']['missing'], 'id'))->toBe([$woreda2->id]);

    // An institution sees only its own figures.
    $scoped = app(ServiceMonitoringSummaryService::class)->build($this->institution, $form, $this->period, null, false);
    expect(json_decode(json_encode(collect($scoped['tables'])->firstWhere('key', 'counts')['fixed']), true)['warning']['all']['staff'])->toBe(2);
});

it('renders the module pages for the bureau', function (): void {
    $form = smPublishedForm();
    $this->actingAs($this->bureau);

    $this->get(route('service-monitoring.forms.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Forms/Index'));
    $this->get(route('service-monitoring.forms.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Forms/Create'));
    $this->get(route('service-monitoring.forms.show', $form))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Forms/Show'));
    $this->get(route('service-monitoring.periods.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Periods/Index'));
    $this->get(route('service-monitoring.reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Reports/Index'));
    $this->get(route('service-monitoring.summary', ['form_code' => $form->code, 'period_id' => $this->period->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Summary/Index')->where('form.code', 'SM-FORM')->has('summary.tables', 2));
});
