<?php

declare(strict_types=1);

use App\Enums\PublicFeedbackQuestionSetStatus;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\PublicServiceTimeBand;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Enums\SubmitterIdentityMode;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\PublicFeedbackAnswer;
use App\Models\PublicFeedbackCategory;
use App\Models\PublicFeedbackQuestionSet;
use App\Models\Service;
use App\Models\User;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackAnswerStatistics;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaire;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaireAdmin;
use App\Services\ServiceFeedback\PublicOrganizationServiceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\PublicFeedbackScenario;

/*
 * The organization public feedback form (docs/organization-public-feedback-form.md):
 * the paper service-feedback questionnaire as a digital form behind the
 * institution's QR. The scanned token alone decides the institution; the
 * service and office must be that institution's; questionnaire answers are
 * stored one typed row each against the published version the visitor saw;
 * Feedback, Tip-off and Complaint stay separate types with their own rules.
 */

beforeEach(function (): void {
    Storage::fake('local');
    $this->withHeader('X-Locale', 'en');

    $this->scenario = PublicFeedbackScenario::employeeTarget('OFF');
    $this->organization = $this->scenario->organization;
    $this->admin = User::factory()->create();
    $this->qr = app(OrganizationFeedbackQrService::class)->ensureActive($this->organization, $this->admin);
    $this->token = $this->qr->public_token;

    // Another institution, whose services and offices must never be accepted on this QR.
    $this->otherOrganization = Organization::query()->create([
        'organization_type_id' => $this->organization->organization_type_id,
        'code' => 'OFF-OTHER',
        'name_en' => 'Other Institution',
        'status' => 'active',
    ]);

    $this->service = offService($this->organization, 'TL-01', 'Trade licence renewal', 'የንግድ ፈቃድ እድሳት');
    offService($this->organization, 'TL-02', 'Draft only service', null, 'draft');
    $this->foreignService = offService($this->otherOrganization, 'OT-01', 'Foreign service', null);

    $this->headOffice = offOffice($this->organization, 'OFF-HQ', 'Head office');
    $this->branch = offOffice($this->organization, 'OFF-BR', 'Bole branch');
    $this->foreignOffice = offOffice($this->otherOrganization, 'OT-HQ', 'Other office');

    $this->questionnaire = offPublishQuestionnaire();

    RateLimiter::clear('sf-submit:'.$this->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
});

function offService(Organization $organization, string $code, string $nameEn, ?string $nameAm, string $status = 'published'): Service
{
    return Service::query()->create([
        'organization_id' => $organization->id,
        'lineage_key' => (string) Str::uuid(),
        'version_no' => 1,
        'code' => $code,
        'name_en' => $nameEn,
        'name_am' => $nameAm,
        'effective_from' => now()->subMonth()->toDateString(),
        'status' => $status,
    ]);
}

function offOffice(Organization $organization, string $code, string $name): OrganizationUnit
{
    return OrganizationUnit::query()->create([
        'organization_id' => $organization->id,
        'code' => $code,
        'name_en' => $name,
        'name_am' => $name.' (አማ)',
        'unit_type' => 'office',
        'status' => 'active',
    ]);
}

/** A published version 1 with every question type the form supports. */
function offPublishQuestionnaire(int $version = 1, string $courtesyLabel = 'Courtesy of the staff'): PublicFeedbackQuestionSet
{
    $set = PublicFeedbackQuestionSet::query()->create([
        'code' => PublicFeedbackQuestionnaire::ORGANIZATION_FORM,
        'version_no' => $version,
        'name_en' => 'Organization service feedback',
        'name_am' => 'የተቋም አገልግሎት አስተያየት',
        'status' => PublicFeedbackQuestionSetStatus::Published,
        'published_at' => now(),
    ]);

    $questions = [
        ['staff_courtesy', 'experience', 'rating', $courtesyLabel, 'የሠራተኞች ትሕትና', true, ['feedback']],
        ['waiting_time', 'experience', 'single_choice', 'About how long did you wait before you were served?', 'አገልግሎት ከመሰጠትዎ በፊት በግምት ስንት ጊዜ ጠበቁ?', false, ['feedback']],
        ['service_obtained', 'details', 'yes_no', 'Did you get the service?', 'አገልግሎቱን አግኝተዋል?', false, ['feedback', 'complaint']],
        ['visit_reason', 'details', 'single_choice', 'Why did you come?', 'ለምን መጡ?', false, ['feedback']],
        ['channels', 'details', 'multiple_choice', 'How did you learn about the service?', 'ስለ አገልግሎቱ እንዴት አወቁ?', false, ['feedback']],
        ['improvement', 'details', 'long_text', 'What should be improved?', 'ምን መሻሻል አለበት?', false, ['feedback']],
        ['desired_resolution', 'details', 'long_text', 'What outcome are you asking for?', 'ምን ዓይነት መፍትሔ ይጠብቃሉ?', false, ['complaint']],
    ];

    foreach ($questions as $index => [$code, $section, $type, $en, $am, $required, $types]) {
        $question = $set->questions()->create([
            'code' => $code, 'section' => $section, 'question_type' => $type, 'label_en' => $en, 'label_am' => $am,
            'is_required' => $required, 'submission_types' => $types, 'sort_order' => $index * 10,
        ]);

        if ($code === 'visit_reason') {
            $question->options()->create(['code' => 'new_application', 'label_en' => 'A new application', 'label_am' => 'አዲስ ማመልከቻ', 'sort_order' => 1]);
            $question->options()->create(['code' => 'follow_up', 'label_en' => 'Following up', 'label_am' => 'ክትትል', 'sort_order' => 2]);
        }

        if ($code === 'waiting_time') {
            $question->options()->create(['code' => 'under_15_minutes', 'label_en' => 'Less than 15 minutes', 'label_am' => 'ከ15 ደቂቃ በታች', 'sort_order' => 1]);
            $question->options()->create(['code' => '15_to_30_minutes', 'label_en' => '15–30 minutes', 'label_am' => '15–30 ደቂቃ', 'sort_order' => 2]);
            $question->options()->create(['code' => '31_to_60_minutes', 'label_en' => '31–60 minutes', 'label_am' => '31–60 ደቂቃ', 'sort_order' => 3]);
            $question->options()->create(['code' => 'over_60_minutes', 'label_en' => 'More than 1 hour', 'label_am' => 'ከ1 ሰዓት በላይ', 'sort_order' => 4]);
            $question->options()->create(['code' => 'not_sure', 'label_en' => 'Not sure', 'label_am' => 'እርግጠኛ አይደለሁም', 'sort_order' => 5]);
        }

        if ($code === 'channels') {
            $question->options()->create(['code' => 'website', 'label_en' => 'Website', 'label_am' => 'ድረ-ገጽ', 'sort_order' => 1]);
            $question->options()->create(['code' => 'radio', 'label_en' => 'Radio', 'label_am' => 'ሬዲዮ', 'sort_order' => 2]);
            $question->options()->create(['code' => 'friend', 'label_en' => 'A friend', 'label_am' => 'ጓደኛ', 'sort_order' => 3]);
        }
    }

    return $set->fresh('questions.options');
}

/** A complete organization Feedback as the multi-step form sends it. */
function offFeedback(array $overrides = []): array
{
    return array_replace([
        'questionnaire_version' => 1,
        'service_id' => test()->service->id,
        'organization_unit_id' => test()->branch->id,
        'service_date' => now()->subDays(2)->toDateString(),
        'service_time_band' => 'morning',
        'rating' => 4,
        'comment' => 'Quick and polite service at the counter.',
        'answers' => [
            'staff_courtesy' => 5,
            'waiting_time' => '15_to_30_minutes',
            'service_obtained' => 'yes',
            'visit_reason' => 'follow_up',
            'channels' => ['website', 'friend'],
            'improvement' => 'More seats in the waiting area.',
        ],
        'client_name' => 'Abebe Kebede',
        'client_contact' => '+251911000000',
    ], $overrides);
}

function offUrl(string $type = ''): string
{
    return '/feedback/'.test()->token.($type === '' ? '' : '/'.$type);
}

it('accepts only effective public-selectable work units from the QR-resolved organization', function (): void {
    $publicUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->organization->id,
        'code' => 'OFF-PERMIT',
        'name_en' => 'Permit Directorate',
        'unit_type' => 'directorate',
        'status' => 'active',
        'effective_from' => now()->subDay()->toDateString(),
        'is_public_feedback_selectable' => true,
    ]);
    $privateUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->organization->id,
        'code' => 'OFF-PRIVATE',
        'name_en' => 'Internal Security',
        'unit_type' => 'directorate',
        'status' => 'active',
        'is_public_feedback_selectable' => false,
    ]);

    $this->post(offUrl('feedback'), offFeedback(['organization_unit_id' => $publicUnit->id, 'unit_selection_mode' => 'SELECTED']))
        ->assertSessionHasNoErrors();

    $entry = EmployeeServiceFeedback::query()->sole();
    expect($entry->organization_unit_id)->toBe($publicUnit->id)
        ->and($entry->organization_unit_name_snapshot)->toBe('Permit Directorate')
        ->and($entry->organization_unit_type_snapshot)->toBe('directorate')
        ->and($entry->unit_selection_mode)->toBe('SELECTED');

    $this->post(offUrl('feedback'), offFeedback(['organization_unit_id' => $privateUnit->id, 'unit_selection_mode' => 'SELECTED']))
        ->assertSessionHasErrors('organization_unit_id');
});

it('records General / Not sure without creating a fake organizational unit', function (): void {
    $this->post(offUrl('complaint'), [
        'questionnaire_version' => 1,
        'service_id' => $this->service->id,
        'unit_selection_mode' => 'GENERAL',
        'message' => 'The office closed early.',
        'client_name' => 'Abebe Kebede',
        'client_contact' => '+251911000000',
    ])->assertSessionHasNoErrors();

    $entry = EmployeeServiceFeedback::query()->sole();
    expect($entry->organization_unit_id)->toBeNull()
        ->and($entry->unit_selection_mode)->toBe('GENERAL')
        ->and($entry->organization_unit_name_snapshot)->toBeNull();
});

/** A complete complaint that names a relevant work unit (the branch unless overridden). */
function offComplaint(array $overrides = []): array
{
    return array_replace([
        'questionnaire_version' => 1,
        'service_id' => test()->service->id,
        'organization_unit_id' => test()->branch->id,
        'unit_selection_mode' => 'SELECTED',
        'message' => 'The office closed early.',
        'client_name' => 'Abebe Kebede',
        'client_contact' => '+251911000000',
    ], $overrides);
}

it('refuses a work unit that is not public-selectable on this QR right now, and stores nothing', function (array $attributes): void {
    $unit = OrganizationUnit::query()->create(array_merge([
        'organization_id' => $this->organization->id, 'code' => 'OFF-X-'.Str::random(4), 'name_en' => 'Not offered unit',
        'unit_type' => 'directorate', 'status' => 'active', 'is_public_feedback_selectable' => true,
    ], $attributes));

    // Not offered on the page either.
    $this->get(offUrl('complaint'))->assertInertia(fn (Assert $page) => $page->where('offices', fn ($offices) => ! collect($offices)->pluck('id')->contains($unit->id)));

    $this->post(offUrl('complaint'), offComplaint(['organization_unit_id' => $unit->id]))->assertSessionHasErrors('organization_unit_id');
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
})->with([
    'a draft unit' => [['status' => 'draft']],
    'an inactive unit' => [['status' => 'inactive']],
    'an archived unit' => [['status' => 'archived']],
    'a unit not yet in force' => [['effective_from' => now()->addDay()->toDateString()]],
    'a unit whose effective window ended' => [['effective_to' => now()->subDay()->toDateString()]],
    'a unit kept off the public form' => [['is_public_feedback_selectable' => false]],
    'another institution\'s unit' => [fn () => ['organization_id' => test()->otherOrganization->id]],
]);

it('refuses a soft-deleted work unit', function (): void {
    $this->branch->delete();

    $this->post(offUrl('complaint'), offComplaint())->assertSessionHasErrors('organization_unit_id');
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('keeps the unit as it was named at submission when it is later renamed or archived, and refuses new submissions to it', function (): void {
    $this->post(offUrl('complaint'), offComplaint())->assertSessionHasNoErrors();

    $this->branch->update(['name_en' => 'Renamed branch', 'name_am' => 'የተቀየረ ቅርንጫፍ']);
    $this->branch->update(['status' => 'archived']);

    $entry = EmployeeServiceFeedback::query()->sole();
    expect($entry->organization_unit_id)->toBe($this->branch->id)
        ->and($entry->organization_unit_name_snapshot)->toBe('Bole branch')
        ->and($entry->organization_unit_name_am_snapshot)->toBe('Bole branch (አማ)')
        ->and($entry->organization_unit_type_snapshot)->toBe('office')
        ->and($entry->relevantUnitName('en'))->toBe('Bole branch')
        ->and($entry->relevantUnitName('am'))->toBe('Bole branch (አማ)');

    // Staff read the recorded name, not the live one.
    $details = app(PublicFeedbackQuestionnaire::class)->presentForStaff($entry->fresh(), 'en');
    expect($details['service_point'])->toBe('Bole branch')->and($details['unit_selection_mode'])->toBe('SELECTED');

    RateLimiter::clear('sf-submit:'.$this->token.'|127.0.0.1');
    $this->post(offUrl('complaint'), offComplaint())->assertSessionHasErrors('organization_unit_id');
    expect(EmployeeServiceFeedback::query()->count())->toBe(1);
});

it('requires a unit or General / Not sure on a complaint and a Tip-off while units are offered, and nothing when none are', function (): void {
    // The current form always states its answer; "SELECTED" with no unit is an incomplete answer.
    $this->post(offUrl('complaint'), offComplaint(['organization_unit_id' => null]))->assertSessionHasErrors('organization_unit_id');
    $this->post(offUrl('suggestion'), pfComplete('suggestion', ['message' => 'Something is wrong.', 'unit_selection_mode' => 'SELECTED']))->assertSessionHasErrors('organization_unit_id');

    // A post that predates the field (no answer key at all) still goes through, as GENERAL.
    $this->post(offUrl('complaint'), array_diff_key(offComplaint(['organization_unit_id' => null]), ['unit_selection_mode' => true]))->assertSessionHasNoErrors();
    expect(EmployeeServiceFeedback::query()->sole()->unit_selection_mode)->toBe('GENERAL');

});

it('asks nothing and blocks nothing at an institution with no public-selectable unit', function (): void {
    OrganizationUnit::query()->where('organization_id', $this->organization->id)->update(['is_public_feedback_selectable' => false]);

    $this->get(offUrl('complaint'))->assertInertia(fn (Assert $page) => $page->where('offices', []));
    $this->post(offUrl('complaint'), offComplaint(['organization_unit_id' => null, 'unit_selection_mode' => 'GENERAL']))->assertSessionHasNoErrors();

    $entry = EmployeeServiceFeedback::query()->sole();
    expect($entry->organization_unit_id)->toBeNull()->and($entry->unit_selection_mode)->toBe('GENERAL');
});

/** A city-wide issue category offered on complaints and feedback about an institution. */
function offCategory(array $overrides = []): PublicFeedbackCategory
{
    return PublicFeedbackCategory::query()->create(array_merge([
        'code' => 'WAIT-'.Str::random(4), 'name_en' => 'Waiting time', 'name_am' => 'የመጠበቂያ ጊዜ',
        'submission_types' => ['feedback', 'complaint'], 'target_types' => ['organization'],
        'organization_id' => null, 'is_active' => true, 'is_public_selectable' => true, 'sort_order' => 1,
    ], $overrides));
}

it('stores the relevant work unit (where) and the issue category (what kind) independently of each other', function (): void {
    $waiting = offCategory();

    // A unit with a category, a unit without one, and General / Not sure with a category.
    $this->post(offUrl('complaint'), offComplaint(['category_id' => $waiting->id]))->assertSessionHasNoErrors();
    $this->post(offUrl('complaint'), offComplaint(['organization_unit_id' => $this->headOffice->id, 'message' => 'Second visit, same problem.']))->assertSessionHasNoErrors();
    $this->post(offUrl('complaint'), offComplaint(['organization_unit_id' => null, 'unit_selection_mode' => 'GENERAL', 'category_id' => $waiting->id, 'message' => 'Not sure which desk.']))->assertSessionHasNoErrors();

    $rows = EmployeeServiceFeedback::query()->orderBy('created_at')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->map(fn (EmployeeServiceFeedback $row): array => [$row->organization_unit_id, $row->unit_selection_mode, $row->category_id])->all())->toBe([
            [$this->branch->id, 'SELECTED', $waiting->id],
            [$this->headOffice->id, 'SELECTED', null],
            [null, 'GENERAL', $waiting->id],
        ])
        // The category never becomes the unit, and the unit never becomes the category.
        ->and($rows->pluck('organization_unit_id')->filter()->all())->not->toContain($waiting->id)
        ->and($rows->pluck('category_id')->filter()->all())->not->toContain($this->branch->id);
});

it('offers an issue category only for the submission types it is configured for, and refuses it elsewhere', function (): void {
    $complaintOnly = offCategory(['code' => 'CONDUCT', 'name_en' => 'Staff conduct', 'submission_types' => ['complaint']]);
    $archived = offCategory(['code' => 'ARCHIVED', 'name_en' => 'Archived category', 'is_active' => false]);
    $internal = offCategory(['code' => 'INTERNAL', 'name_en' => 'Internal flag', 'is_public_selectable' => false]);

    $this->get(offUrl('complaint'))->assertInertia(fn (Assert $page) => $page->where('categories', fn ($rows) => collect($rows)->pluck('name')->all() === ['Staff conduct']));
    $this->get(offUrl('feedback'))->assertInertia(fn (Assert $page) => $page->where('categories', []));

    // Category configured for complaints only, posted on a Tip-off by hand.
    $this->post(offUrl('suggestion'), pfComplete('suggestion', ['message' => 'Something is wrong.', 'unit_selection_mode' => 'GENERAL', 'category_id' => $complaintOnly->id]))->assertSessionHasErrors('category_id');
    $this->post(offUrl('complaint'), offComplaint(['category_id' => $archived->id]))->assertSessionHasErrors('category_id');
    $this->post(offUrl('complaint'), offComplaint(['category_id' => $internal->id]))->assertSessionHasErrors('category_id');
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('explains an invalid work unit in Amharic', function (): void {
    $this->withHeader('X-Locale', 'am')
        ->post(offUrl('complaint'), offComplaint(['organization_unit_id' => $this->foreignOffice->id]))
        ->assertSessionHasErrors(['organization_unit_id' => 'እባክዎ ከዚህ ተቋም የሚገኝ ትክክለኛ የሥራ ክፍል ይምረጡ።']);
});

it('offers each unit with its parent unit when that parent is offered too, in the visitor\'s language, and never a code, a manager or staff', function (): void {
    $desk = OrganizationUnit::query()->create([
        'organization_id' => $this->organization->id, 'code' => 'OFF-HQ-PERMITS', 'name_en' => 'Permits desk', 'name_am' => 'የፈቃድ ዴስክ',
        'unit_type' => 'team', 'status' => 'active', 'parent_unit_id' => $this->headOffice->id, 'is_public_feedback_selectable' => true,
    ]);
    // Its parent is internal: the visitor sees the unit on its own, with no path into the internal structure.
    $internal = OrganizationUnit::query()->create([
        'organization_id' => $this->organization->id, 'code' => 'OFF-INT', 'name_en' => 'Internal directorate', 'unit_type' => 'directorate', 'status' => 'active',
    ]);
    $orphan = OrganizationUnit::query()->create([
        'organization_id' => $this->organization->id, 'code' => 'OFF-INT-DESK', 'name_en' => 'Public desk', 'unit_type' => 'team', 'status' => 'active',
        'parent_unit_id' => $internal->id, 'is_public_feedback_selectable' => true,
    ]);

    $this->get(offUrl('complaint'))->assertInertia(fn (Assert $page) => $page
        ->where('offices', fn ($offices) => collect($offices)->firstWhere('id', $desk->id) === ['id' => $desk->id, 'name' => 'Permits desk', 'type' => 'team', 'parent_id' => $this->headOffice->id, 'parent' => 'Head office'])
        ->where('offices', fn ($offices) => collect($offices)->firstWhere('id', $orphan->id)['parent'] === null)
        ->where('offices', fn ($offices) => collect($offices)->every(fn (array $unit): bool => array_keys($unit) === ['id', 'name', 'type', 'parent_id', 'parent']))
        ->where('offices', fn ($offices) => ! str_contains(json_encode($offices), 'OFF-HQ') && ! collect($offices)->pluck('id')->contains($internal->id)));

    $this->withHeader('X-Locale', 'am')->get(offUrl('complaint'))->assertInertia(fn (Assert $page) => $page
        ->where('offices', fn ($offices) => collect($offices)->firstWhere('id', $desk->id)['name'] === 'የፈቃድ ዴስክ'
            && collect($offices)->firstWhere('id', $desk->id)['parent'] === 'Head office (አማ)'));
});

// ── Rendering ────────────────────────────────────────────────────────────

it('renders the institution, its published services and offices, and the questionnaire of the type — never an internal id', function (): void {
    $this->get(offUrl('feedback'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ServiceFeedback')
            ->where('target.display_name', 'Feedback Organization')
            ->missing('target.id')
            ->missing('target.organization_id')
            ->where('questionnaire.version', 1)
            ->where('questionnaire.questions', fn ($questions) => collect($questions)->pluck('code')->all() === ['staff_courtesy', 'waiting_time', 'service_obtained', 'visit_reason', 'channels', 'improvement'])
            ->where('questionnaire.questions.0.label', 'Courtesy of the staff')
            ->where('questionnaire.questions.0.required', true)
            ->where('questionnaire.questions.1.label', 'About how long did you wait before you were served?')
            ->where('questionnaire.questions.1.options.1.code', '15_to_30_minutes')
            ->where('questionnaire.questions.3.options.1.code', 'follow_up')
            ->where('serviceOptions.mode', 'list')
            // Only this institution's PUBLISHED catalog: no draft, nothing of another institution.
            ->where('serviceOptions.items', [['id' => $this->service->id, 'code' => 'TL-01', 'name' => 'Trade licence renewal']])
            ->where('offices', fn ($offices) => collect($offices)->pluck('id')->sort()->values()->all() === collect([$this->headOffice->id, $this->branch->id])->sort()->values()->all())
            ->where('links.serviceSearch', url('/feedback/'.$this->token.'/services')));
});

it('shows the complaint its own questions and no rating items', function (): void {
    $this->get(offUrl('complaint'))->assertInertia(fn (Assert $page) => $page
        ->where('questionnaire.questions', fn ($questions) => collect($questions)->pluck('code')->all() === ['service_obtained', 'desired_resolution']));
});

it('gives the Tip-off no questionnaire when no question applies to it', function (): void {
    $this->get(offUrl('suggestion'))->assertInertia(fn (Assert $page) => $page->where('questionnaire', null));
});

it('renders the questionnaire and service names in Amharic for an Amharic visitor', function (): void {
    $this->withHeader('X-Locale', 'am')->get(offUrl('feedback'))->assertInertia(fn (Assert $page) => $page
        ->where('target.display_name', 'የአስተያየት ተቋም')
        ->where('questionnaire.questions.0.label', 'የሠራተኞች ትሕትና')
        ->where('serviceOptions.items.0.name', 'የንግድ ፈቃድ እድሳት'));
});

it('offers the remaining public work unit, and free text when it has no catalog', function (): void {
    $this->branch->update(['status' => 'inactive']);
    $this->service->update(['status' => 'superseded']);

    $this->get(offUrl('feedback'))->assertInertia(fn (Assert $page) => $page
        ->where('offices', [['id' => $this->headOffice->id, 'name' => 'Head office', 'type' => 'office', 'parent_id' => null, 'parent' => null]])
        ->where('serviceOptions', ['mode' => 'none', 'items' => []]));
});

it('sends a search box instead of the list when the catalog is large', function (): void {
    foreach (range(1, PublicOrganizationServiceContext::LIST_LIMIT) as $n) {
        offService($this->organization, 'BULK-'.$n, 'Bulk service '.$n, null);
    }

    $this->get(offUrl('feedback'))->assertInertia(fn (Assert $page) => $page->where('serviceOptions', ['mode' => 'search', 'items' => []]));
});

it('the employee QR form carries no organization questionnaire or service catalog', function (): void {
    $this->get('/service-feedback/'.$this->scenario->token->token.'/feedback')->assertInertia(fn (Assert $page) => $page
        ->where('questionnaire', null)
        ->where('offices', [])
        ->where('serviceOptions.mode', 'none'));
});

// ── Feedback ─────────────────────────────────────────────────────────────

it('stores an organization Feedback with service information and every structured answer typed', function (): void {
    $this->post(offUrl('feedback'), offFeedback())->assertSessionHasNoErrors()->assertRedirect(offUrl('feedback'));

    $row = EmployeeServiceFeedback::query()->sole();

    expect($row->type)->toBe(ServiceFeedbackType::Feedback)
        ->and($row->target_type)->toBe(PublicFeedbackTargetType::Organization)
        ->and($row->organization_target_id)->toBe($this->organization->id)
        ->and($row->rating)->toBe(4)
        ->and($row->service_id)->toBe($this->service->id)
        ->and($row->service_no_snapshot)->toBe('TL-01')
        ->and($row->service_name_snapshot)->toBe('Trade licence renewal')
        ->and($row->service_text)->toBeNull()
        ->and($row->organization_unit_id)->toBe($this->branch->id)
        ->and($row->service_date->toDateString())->toBe(now()->subDays(2)->toDateString())
        ->and($row->service_time_band)->toBe(PublicServiceTimeBand::Morning)
        ->and($row->question_set_id)->toBe($this->questionnaire->id);

    $answers = PublicFeedbackAnswer::query()->where('submission_id', $row->id)->with('option')->get()->groupBy('question_code');

    expect($answers->keys()->sort()->values()->all())->toBe(['channels', 'improvement', 'service_obtained', 'staff_courtesy', 'visit_reason', 'waiting_time'])
        ->and($answers['staff_courtesy']->sole()->rating_value)->toBe(5)
        ->and($answers['waiting_time']->sole()->option->code)->toBe('15_to_30_minutes')
        ->and($answers['service_obtained']->sole()->bool_value)->toBeTrue()
        ->and($answers['visit_reason']->sole()->option->code)->toBe('follow_up')
        ->and($answers['channels']->map(fn ($answer) => $answer->option->code)->sort()->values()->all())->toBe(['friend', 'website'])
        ->and($answers['improvement']->sole()->text_value)->toBe('More seats in the waiting area.')
        ->and(PublicFeedbackAnswer::query()->where('question_set_id', '!=', $this->questionnaire->id)->exists())->toBeFalse();

    $this->get(offUrl('feedback'))->assertInertia(fn (Assert $page) => $page
        ->where('submitted', true)
        ->where('receipt.type', 'feedback')
        ->where('receipt.target_name', 'Feedback Organization')
        ->has('receipt.submitted_at'));
});

it('keeps unanswered optional questions out of the answers and stores the visitor\'s own words when the service is not listed', function (): void {
    $this->post(offUrl('feedback'), offFeedback([
        'service_id' => null,
        'service_text' => '  Renewing a stamp  ',
        'organization_unit_id' => null,
        'service_date' => null,
        'service_time_band' => null,
        'answers' => ['staff_courtesy' => 3],
    ]))->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();

    expect($row->service_id)->toBeNull()
        ->and($row->service_text)->toBe('Renewing a stamp')
        ->and($row->service_name_snapshot)->toBeNull()
        ->and($row->organization_unit_id)->toBeNull()
        ->and($row->answers()->pluck('question_code')->all())->toBe(['staff_courtesy']);
});

it('refuses a Feedback that leaves a required question unanswered, naming the question', function (): void {
    $payload = offFeedback();
    unset($payload['answers']['staff_courtesy']);

    $this->post(offUrl('feedback'), $payload)
        ->assertSessionHasErrors(['answers.staff_courtesy' => 'Please answer: Courtesy of the staff']);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('refuses out-of-range ratings, unknown choices and over-long text', function (array $answers, string $key): void {
    $payload = offFeedback();
    $payload['answers'] = array_replace($payload['answers'], $answers);

    $this->post(offUrl('feedback'), $payload)->assertSessionHasErrors([$key]);
    expect(PublicFeedbackAnswer::query()->count())->toBe(0);
})->with([
    'rating above 5' => [['staff_courtesy' => 6], 'answers.staff_courtesy'],
    'unknown waiting-time choice' => [['waiting_time' => 'two_hours'], 'answers.waiting_time'],
    'yes/no other value' => [['service_obtained' => 'maybe'], 'answers.service_obtained'],
    'unknown single choice' => [['visit_reason' => 'bribery'], 'answers.visit_reason'],
    'unknown multiple choice' => [['channels' => ['website', 'tv']], 'answers.channels.1'],
    'text too long' => [['improvement' => str_repeat('a', 2001)], 'answers.improvement'],
]);

it('does not ask a Feedback for any Complaint or Tip-off field', function (): void {
    $this->post(offUrl('feedback'), offFeedback())->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->reference_number)->toBeNull()
        ->and($row->incident_date)->toBeNull()
        ->and($row->tip_type)->toBeNull()
        ->and($row->attachments()->count())->toBe(0);
});

it('refuses an answer to a question the Feedback was not asked', function (): void {
    $payload = offFeedback();
    $payload['answers']['desired_resolution'] = 'A refund';

    $this->post(offUrl('feedback'), $payload)->assertSessionHasErrors(['answers']);
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

// ── Complaint ────────────────────────────────────────────────────────────

it('stores a Complaint with its own answers and evidence, without the Feedback rating items', function (): void {
    $this->post(offUrl('complaint'), [
        'questionnaire_version' => 1,
        'service_id' => $this->service->id,
        'service_date' => now()->subDay()->toDateString(),
        'message' => 'I was sent between three windows for one stamp.',
        'incident_location' => 'Window 4',
        'answers' => ['service_obtained' => 'no', 'desired_resolution' => 'Serve me at one window.'],
        'client_name' => 'Abebe Kebede',
        'client_contact' => '+251911000000',
        'attachments' => [UploadedFile::fake()->image('queue.png', 80, 80)],
    ])->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();

    expect($row->type)->toBe(ServiceFeedbackType::Complaint)
        ->and($row->reference_number)->toMatch('/^PSC-\d{4}-\d{6}$/')
        ->and($row->rating)->toBeNull()
        ->and($row->comment)->toBe('I was sent between three windows for one stamp.')
        ->and($row->service_id)->toBe($this->service->id)
        ->and($row->question_set_id)->toBe($this->questionnaire->id)
        ->and($row->attachments()->count())->toBe(1)
        // No routing rule exists: kept and flagged, never lost.
        ->and($row->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and($row->answers()->where('question_code', 'service_obtained')->value('bool_value'))->toBeFalse()
        ->and($row->answers()->where('question_code', 'desired_resolution')->value('text_value'))->toBe('Serve me at one window.');

    $this->get(offUrl('complaint'))->assertInertia(fn (Assert $page) => $page
        ->where('submitted', true)
        ->where('receipt.reference', $row->reference_number));
});

it('stores a Complaint without evidence and without answering optional questions', function (): void {
    $this->post(offUrl('complaint'), pfComplete('complaint', ['message' => 'The office closed early.', 'questionnaire_version' => 1]))->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->attachments()->count())->toBe(0)
        ->and($row->answers()->count())->toBe(0)
        // The visitor was shown this version's questions; the row says which.
        ->and($row->question_set_id)->toBe($this->questionnaire->id);
});

it('accepts a Complaint from a page that showed no questionnaire when its questions are all optional, recording no version', function (): void {
    // The Complaint's questions are optional: a page opened before they were published loses nothing required.
    $this->post(offUrl('complaint'), pfComplete('complaint', ['message' => 'The office closed early.']))->assertSessionHasNoErrors();

    expect(EmployeeServiceFeedback::query()->sole()->question_set_id)->toBeNull();

    // Answers with no version are answers to wording nobody can name: refused.
    $this->post(offUrl('complaint'), pfComplete('complaint', ['message' => 'Another visit.', 'answers' => ['service_obtained' => 'no']]))
        ->assertSessionHasErrors(['answers']);
});

it('shows the exact error for a refused evidence file and keeps nothing', function (): void {
    $this->post(offUrl('complaint'), pfComplete('complaint', [
        'message' => 'The office closed early.',
        'attachments' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
    ]))->assertSessionHasErrors(['attachments.0']);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

// ── Tip-off (internal type suggestion) ───────────────────────────────────

it('accepts an anonymous Tip-off about the institution without identity, evidence or questionnaire', function (): void {
    $this->post(offUrl('suggestion'), [
        'tip_type' => 'IMPROPER_PRACTICE',
        'message' => 'Applicants are told to come back without a reason.',
        'service_id' => $this->service->id,
        'organization_unit_id' => $this->headOffice->id,
    ])->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();

    expect($row->type)->toBe(ServiceFeedbackType::Suggestion)
        ->and($row->client_name)->toBeNull()
        ->and($row->client_contact)->toBeNull()
        ->and($row->submitter_identity_mode)->toBe(SubmitterIdentityMode::Optional)
        ->and($row->reference_number)->toMatch('/^PTO-\d{4}-\d{6}$/')
        ->and($row->service_id)->toBe($this->service->id)
        ->and($row->organization_unit_id)->toBe($this->headOffice->id)
        ->and($row->question_set_id)->toBeNull()
        ->and($row->answers()->count())->toBe(0);
});

it('stores an identified Tip-off with evidence', function (): void {
    $this->post(offUrl('suggestion'), [
        'tip_type' => 'CORRUPTION',
        'message' => 'A fee was asked for outside the receipt.',
        'client_name' => 'Almaz Tesfaye',
        'client_contact' => '+251922000000',
        'attachments' => [UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')],
    ])->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->client_name)->toBe('Almaz Tesfaye')->and($row->attachments()->count())->toBe(1);
});

it('refuses questionnaire answers on a Tip-off, which is asked none', function (): void {
    $this->post(offUrl('suggestion'), pfComplete('suggestion', ['message' => 'Facts.', 'answers' => ['staff_courtesy' => 1]]))
        ->assertSessionHasErrors(['answers']);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

// ── Versions ─────────────────────────────────────────────────────────────

it('refuses a submission answering a version that is no longer published, and stores nothing', function (): void {
    $this->post(offUrl('feedback'), offFeedback(['questionnaire_version' => 7]))
        ->assertSessionHasErrors(['questionnaire_version' => 'This form was updated while you were filling it in. Please review the questions and send again.']);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('refuses answers when the questionnaire was withdrawn while the form was open, but accepts the core form', function (): void {
    $this->questionnaire->update(['status' => PublicFeedbackQuestionSetStatus::Retired]);

    $this->post(offUrl('feedback'), offFeedback())->assertSessionHasErrors(['questionnaire_version']);

    $payload = offFeedback(['questionnaire_version' => null]);
    unset($payload['answers']);
    $this->post(offUrl('feedback'), $payload)->assertSessionHasNoErrors();

    expect(EmployeeServiceFeedback::query()->sole()->question_set_id)->toBeNull();
});

it('keeps the wording a visitor answered after a newer version rewords the question', function (): void {
    $this->post(offUrl('feedback'), offFeedback())->assertSessionHasNoErrors();
    $first = EmployeeServiceFeedback::query()->sole();

    // Publish version 2 through the admin service: version 1 is retired, never edited.
    $cityAdmin = User::factory()->create();
    $cityAdmin->givePermissionTo(Permission::findOrCreate(PublicFeedbackQuestionnaireAdmin::PERMISSION, 'web'));
    $admin = app(PublicFeedbackQuestionnaireAdmin::class);
    $draft = $admin->createDraft($cityAdmin);
    $questions = $draft->questions()->with('options')->get()->map(fn ($question) => [
        'code' => $question->code, 'section' => $question->section->value, 'question_type' => $question->question_type->value,
        'label_en' => $question->code === 'staff_courtesy' ? 'How respectful was the staff?' : $question->label_en,
        'label_am' => $question->label_am, 'is_required' => $question->is_required, 'submission_types' => $question->submission_types,
        'options' => $question->options->map(fn ($option) => ['code' => $option->code, 'label_en' => $option->label_en, 'label_am' => $option->label_am])->all(),
    ])->all();
    $admin->saveDraft($cityAdmin, $draft, ['name_en' => 'Organization service feedback', 'name_am' => 'የተቋም አገልግሎት አስተያየት', 'questions' => $questions]);
    $admin->publish($cityAdmin, $draft->fresh());

    expect($this->questionnaire->fresh()->status)->toBe(PublicFeedbackQuestionSetStatus::Retired);

    app()->setLocale('en');
    $staffView = app(PublicFeedbackQuestionnaire::class)->presentForStaff($first->fresh(), 'en');
    expect($staffView['questionnaire_version'])->toBe(1)
        ->and(collect($staffView['answers'])->firstWhere('code', 'staff_courtesy')['question'])->toBe('Courtesy of the staff');

    // New submissions answer version 2.
    $this->post(offUrl('feedback'), offFeedback(['questionnaire_version' => 2, 'comment' => 'Second visit, also fine.']))->assertSessionHasNoErrors();
    $second = EmployeeServiceFeedback::query()->where('id', '!=', $first->id)->sole();
    expect($second->questionSet->version_no)->toBe(2)
        ->and(collect(app(PublicFeedbackQuestionnaire::class)->presentForStaff($second, 'en')['answers'])->firstWhere('code', 'staff_courtesy')['question'])->toBe('How respectful was the staff?');
});

it('leaves submissions made before the organization form untouched and still readable', function (): void {
    $this->questionnaire->update(['status' => PublicFeedbackQuestionSetStatus::Retired]);
    $payload = offFeedback(['questionnaire_version' => null, 'service_id' => null, 'organization_unit_id' => null, 'service_date' => null, 'service_time_band' => null]);
    unset($payload['answers']);
    $this->post(offUrl('feedback'), $payload)->assertSessionHasNoErrors();

    $legacy = EmployeeServiceFeedback::query()->sole();
    expect(app(PublicFeedbackQuestionnaire::class)->presentForStaff($legacy, 'en'))->toMatchArray([
        'service' => null,
        'service_point' => null,
        'unit_selection_mode' => 'GENERAL',
        'questionnaire_version' => null,
        'answers' => [],
    ])
        ->and($legacy->answers()->count())->toBe(0);
});

// ── Target security ──────────────────────────────────────────────────────

it('refuses a service or an office of another institution, and stores nothing', function (array $overrides, string $key): void {
    $this->post(offUrl('feedback'), offFeedback($overrides))->assertSessionHasErrors([$key]);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
})->with([
    'foreign service' => [fn () => ['service_id' => test()->foreignService->id], 'service_id'],
    'unpublished service' => [fn () => ['service_id' => Service::query()->where('code', 'TL-02')->value('id')], 'service_id'],
    'foreign office' => [fn () => ['organization_unit_id' => test()->foreignOffice->id], 'organization_unit_id'],
    'internal non-office unit' => [fn () => ['organization_unit_id' => test()->scenario->unit->id], 'organization_unit_id'],
]);

it('keeps the scanned institution as the target whatever ids the browser posts', function (): void {
    $this->post(offUrl('feedback'), offFeedback([
        'organization_id' => $this->otherOrganization->id,
        'organization_target_id' => $this->otherOrganization->id,
        'target_type' => 'employee',
        'employee_id' => $this->scenario->employee->id,
    ]))->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->organization_target_id)->toBe($this->organization->id)
        ->and($row->organization_id)->toBe($this->organization->id)
        ->and($row->target_type)->toBe(PublicFeedbackTargetType::Organization)
        ->and($row->employee_id)->toBeNull();
});

it('blocks a submission when the QR was disabled after the form was opened', function (): void {
    $this->get(offUrl('feedback'))->assertOk();
    app(OrganizationFeedbackQrService::class)->disable($this->qr->fresh(), $this->admin);

    $this->post(offUrl('feedback'), offFeedback())->assertRedirect(url('/feedback/'.$this->token));

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('shows the same unavailable page for an archived institution, an unknown token and a tampered token', function (string $token): void {
    $this->get('/feedback/'.$token.'/feedback')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('available', false)
        ->where('questionnaire', null)
        ->where('serviceOptions.items', []));
})->with([
    'archived institution' => [function () {
        test()->organization->update(['status' => 'archived']);

        return test()->token;
    }],
    'unknown token' => [fn () => str_repeat('a', 64)],
    'tampered token' => [fn () => substr(test()->token, 0, 63).(str_ends_with(test()->token, '0') ? '1' : '0')],
]);

it('refuses organization-form fields on the employee QR', function (): void {
    $this->post('/service-feedback/'.$this->scenario->token->token.'/feedback', pfComplete('feedback', [
        'rating' => 4,
        'position_service_id' => $this->scenario->positionService->id,
        'service_id' => $this->service->id,
        'answers' => ['staff_courtesy' => 5],
    ]))->assertSessionHasErrors(['service_id', 'answers']);
});

// ── Service search ───────────────────────────────────────────────────────

it('searches only the scanned institution\'s published services, and answers an unknown token with nothing', function (): void {
    $this->getJson('/feedback/'.$this->token.'/services?q=licence')
        ->assertOk()
        ->assertExactJson(['items' => [['id' => $this->service->id, 'code' => 'TL-01', 'name' => 'Trade licence renewal']]]);

    $this->getJson('/feedback/'.$this->token.'/services?q=Foreign')->assertExactJson(['items' => []]);
    $this->getJson('/feedback/'.$this->token.'/services?q=D')->assertExactJson(['items' => []]);
    $this->getJson('/feedback/'.str_repeat('b', 64).'/services?q=licence')->assertOk()->assertExactJson(['items' => []]);
});

// ── Localization and analytics ───────────────────────────────────────────

it('gives questionnaire errors in Amharic to an Amharic visitor', function (): void {
    $payload = offFeedback();
    unset($payload['answers']['staff_courtesy']);

    $this->withHeader('X-Locale', 'am')->post(offUrl('feedback'), $payload)
        ->assertSessionHasErrors(['answers.staff_courtesy' => 'እባክዎ ይህንን ይመልሱ፦ የሠራተኞች ትሕትና']);
});

it('aggregates ratings and choices by question for the Situation Room, leaving hidden submissions out', function (): void {
    $this->post(offUrl('feedback'), offFeedback())->assertSessionHasNoErrors();
    RateLimiter::clear('sf-submit:'.$this->token.'|127.0.0.1');
    $this->post(offUrl('feedback'), offFeedback(['comment' => 'Second.', 'answers' => ['staff_courtesy' => 3, 'channels' => ['website'], 'service_obtained' => 'no']]))->assertSessionHasNoErrors();
    RateLimiter::clear('sf-submit:'.$this->token.'|127.0.0.1');
    $this->post(offUrl('feedback'), offFeedback(['comment' => 'Hidden one.', 'answers' => ['staff_courtesy' => 1]]))->assertSessionHasNoErrors();
    EmployeeServiceFeedback::query()->where('comment', 'Hidden one.')->update(['status' => 'hidden']);

    $statistics = app(PublicFeedbackAnswerStatistics::class);
    $summary = collect($statistics->ratingSummary(['organization_target_id' => $this->organization->id, 'service_id' => $this->service->id]))->keyBy('code');

    expect($summary['staff_courtesy'])->toBe(['code' => 'staff_courtesy', 'answered' => 2, 'average' => 4.0, 'distribution' => [1 => 0, 2 => 0, 3 => 1, 4 => 0, 5 => 1]])
        ->and(collect($statistics->choiceCounts('waiting_time', ['organization_target_id' => $this->organization->id, 'service_id' => $this->service->id]))->sortKeys()->all())->toBe(['15_to_30_minutes' => 1])
        ->and(collect($statistics->choiceCounts('channels', ['organization_target_id' => $this->organization->id]))->sortKeys()->all())->toBe(['friend' => 1, 'website' => 2])
        ->and(collect($statistics->choiceCounts('service_obtained'))->sortKeys()->all())->toBe(['no' => 1, 'yes' => 1])
        ->and($statistics->ratingSummary(['organization_target_id' => $this->otherOrganization->id]))->toBe([]);
});

// ── Production gate: validation, tampering and QR state ──────────────────

it('refuses a form opened before a questionnaire was published, so no question is skipped unseen', function (): void {
    $this->questionnaire->update(['status' => PublicFeedbackQuestionSetStatus::Retired]);
    offPublishQuestionnaire(2);
    // What a page rendered with no questionnaire sends: no version, no answers.
    $payload = offFeedback(['questionnaire_version' => null]);
    unset($payload['answers']);

    $this->post(offUrl('feedback'), $payload)
        ->assertSessionHasErrors(['questionnaire_version' => 'This form was updated while you were filling it in. Please review the questions and send again.']);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('refuses a choice belonging to another question and malformed answers', function (array $answers, array $overrides, string $key): void {
    $payload = offFeedback($overrides);
    $payload['answers'] = array_replace($payload['answers'], $answers);

    $this->post(offUrl('feedback'), $payload)->assertSessionHasErrors([$key]);
    expect(EmployeeServiceFeedback::query()->count())->toBe(0)
        ->and(PublicFeedbackAnswer::query()->count())->toBe(0);
})->with([
    'option of another question' => [['visit_reason' => 'website'], [], 'answers.visit_reason'],
    'decimal rating' => [['staff_courtesy' => '4.5'], [], 'answers.staff_courtesy'],
    'word as rating' => [['staff_courtesy' => 'five'], [], 'answers.staff_courtesy'],
    'rating as a list' => [['staff_courtesy' => [5]], [], 'answers.staff_courtesy'],
    'single choice as a list' => [['visit_reason' => ['follow_up']], [], 'answers.visit_reason'],
    'the same choice twice' => [['channels' => ['website', 'website']], [], 'answers.channels.0'],
    'more choices than offered' => [['channels' => ['website', 'radio', 'friend', 'website']], [], 'answers.channels'],
    'written answer as a list' => [['improvement' => ['a', 'b']], [], 'answers.improvement'],
    'overall rating out of range' => [[], ['rating' => 6], 'rating'],
    'overall rating not whole' => [[], ['rating' => '3.5'], 'rating'],
    // Dates are built when the case runs: Pest collects datasets before the app sets its own timezone.
    'service date in the future' => [[], fn (): array => ['service_date' => now()->addDay()->toDateString()], 'service_date'],
    'service date in another format' => [[], fn (): array => ['service_date' => now()->format('d/m/Y')], 'service_date'],
    'unknown time band' => [[], ['service_time_band' => 'midnight'], 'service_time_band'],
    'service id that is not a uuid' => [[], ['service_id' => '1 OR 1=1'], 'service_id'],
]);

it('stores Amharic, English and markup in written answers exactly, as plain text', function (): void {
    $comment = '<script>alert(1)</script> አገልግሎቱ ፈጣን ነበር — thank you';
    $improvement = '<img src=x onerror=alert(1)> የመጠበቂያ ቦታው ይስፋ። More seats.';
    $payload = offFeedback(['comment' => $comment]);
    $payload['answers']['improvement'] = $improvement;

    $this->post(offUrl('feedback'), $payload)->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    // Stored as typed; every screen renders it as text (React escapes it), never as HTML.
    expect($row->comment)->toBe($comment)
        ->and(PublicFeedbackAnswer::query()->where('question_code', 'improvement')->sole()->text_value)->toBe($improvement);
});

it('ignores workflow, reference and version fields a browser adds to the payload', function (): void {
    $this->post(offUrl('feedback'), offFeedback([
        'status' => 'resolved',
        'reference_number' => 'PSC-FORGED-1',
        'question_set_id' => (string) Str::uuid(),
        'intake_channel' => 'phone',
        'submitter_identity_mode' => 'anonymous',
        'source_organization_qr_id' => (string) Str::uuid(),
        'organization_target_id' => $this->otherOrganization->id,
        'organization_id' => $this->otherOrganization->id,
        'target_type' => 'employee',
    ]))->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->status)->toBe(ServiceFeedbackStatus::Pending)
        ->and($row->reference_number)->toBeNull()
        ->and($row->question_set_id)->toBe($this->questionnaire->id)
        ->and($row->intake_channel->value)->toBe('WEB_QR')
        ->and($row->submitter_identity_mode)->toBe(SubmitterIdentityMode::Identified)
        ->and($row->source_organization_qr_id)->toBe($this->qr->id)
        ->and($row->organization_target_id)->toBe($this->organization->id)
        ->and($row->organization_id)->toBe($this->organization->id)
        ->and($row->target_type)->toBe(PublicFeedbackTargetType::Organization);
});

it('blocks a submission once the QR is revoked or the institution deactivated, and stores nothing', function (string $case): void {
    $this->get(offUrl('feedback'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('available', true));

    $case === 'revoked'
        ? app(OrganizationFeedbackQrService::class)->revoke($this->qr->fresh(), 'Sticker stolen', $this->admin)
        : $this->organization->update(['status' => 'inactive']);

    $this->post(offUrl('feedback'), offFeedback())->assertRedirect(url('/feedback/'.$this->token));

    expect(EmployeeServiceFeedback::query()->count())->toBe(0)
        ->and(PublicFeedbackAnswer::query()->count())->toBe(0);
    $this->get(offUrl('feedback'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('available', false)
        ->where('target', null)
        ->where('formToken', null));
})->with(['revoked', 'inactive']);

it('stores a double-tapped Feedback once, with one set of answers', function (): void {
    $this->post(offUrl('feedback'), offFeedback())->assertSessionHasNoErrors();
    // Feature requests do not carry cookies automatically; mimic one browser session.
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->post(offUrl('feedback'), offFeedback())->assertSessionHasNoErrors();

    expect(EmployeeServiceFeedback::query()->count())->toBe(1)
        ->and(PublicFeedbackAnswer::query()->count())->toBe(7);
});

it('gives the receipt only the type, the institution, the reference and the time', function (): void {
    $this->post(offUrl('complaint'), [
        'questionnaire_version' => 1,
        'service_id' => $this->service->id,
        'message' => 'I was asked for an unofficial fee at the counter.',
        'client_name' => 'Abebe Kebede',
        'client_contact' => '+251911000000',
    ])->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    $receipt = null;
    $this->get(offUrl('complaint'))->assertInertia(function (Assert $page) use (&$receipt): void {
        $receipt = $page->toArray()['props']['receipt'];
    });

    expect(array_keys($receipt))->toBe(['type', 'target_type', 'target_name', 'reference', 'tip_type', 'submitted_at'])
        ->and($receipt['reference'])->toBe($row->reference_number)
        ->and($receipt['reference'])->toStartWith('PSC-')
        ->and($receipt['reference'])->not->toBe((string) $row->id)
        ->and(json_encode($receipt))->not->toContain($row->id)->not->toContain($this->organization->id)
        ->not->toContain('Abebe')->not->toContain('+251911000000')->not->toContain('unofficial fee');
});

it('loads the published questionnaire once per request, whatever reads it', function (): void {
    DB::enableQueryLog();
    $this->post(offUrl('feedback'), offFeedback())->assertSessionHasNoErrors();
    $reads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "public_feedback_question_sets"'))->count();
    DB::disableQueryLog();

    expect($reads)->toBe(1);
});

it('serves the institution logo through the scanned token, never by organization id or storage path', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('organizations/logos/'.$this->organization->id.'/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    $this->organization->update(['logo_path' => 'organizations/logos/'.$this->organization->id.'/logo.png']);

    $props = null;
    $this->get(offUrl('feedback'))->assertOk()->assertInertia(function (Assert $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });
    $public = json_encode(array_intersect_key($props, array_flip(['target', 'links', 'questionnaire', 'serviceOptions', 'offices', 'call', 'receipt'])));

    expect($props['target']['logo_url'])->toStartWith('/feedback/'.$this->token.'/logo?v=')
        ->and($public)->not->toContain($this->organization->id)->not->toContain('/storage/');

    $this->get($props['target']['logo_url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

    // Unknown and disabled tokens answer the same 404.
    $this->get('/feedback/'.str_repeat('b', 64).'/logo')->assertNotFound();
    app(OrganizationFeedbackQrService::class)->disable($this->qr->fresh(), $this->admin);
    $this->get('/feedback/'.$this->token.'/logo')->assertNotFound();
});
