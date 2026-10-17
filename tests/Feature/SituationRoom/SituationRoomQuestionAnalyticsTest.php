<?php

declare(strict_types=1);

use App\Enums\PublicFeedbackQuestionSetStatus;
use App\Models\EmployeeServiceFeedback;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationEdge;
use App\Models\PublicFeedbackAnswer;
use App\Models\PublicFeedbackQuestion;
use App\Models\PublicFeedbackQuestionSet;
use App\Models\Service;
use App\Models\User;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaire;
use App\Services\SituationRoom\SituationPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SituationRoomScenario;

/*
 * The organization feedback form's structured answers in the Executive
 * Situation Room (docs/organization-feedback-analytics.md): per-question
 * rating distributions and choice counts, built on the same
 * hierarchy-scoped base as every Public Voice panel. Bureau → Subcity A
 * (Woreda A1, A2) / Subcity B (Woreda B1): a parent sees its descendants'
 * answers, a sibling never does, and no respondent or free text leaves the
 * database.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    $this->s = SituationRoomScenario::build();

    $this->set = PublicFeedbackQuestionSet::query()->create([
        'code' => PublicFeedbackQuestionnaire::ORGANIZATION_FORM, 'version_no' => 1, 'name_en' => 'Form', 'name_am' => 'ቅጽ',
        'status' => PublicFeedbackQuestionSetStatus::Published, 'published_at' => now(),
    ]);
    $types = ['feedback', 'suggestion', 'complaint'];
    $this->courtesy = $this->set->questions()->create(['code' => 'staff_courtesy', 'section' => 'experience', 'question_type' => 'rating', 'label_en' => 'Courtesy of the staff', 'label_am' => 'የሠራተኞች ትሕትና', 'is_required' => false, 'submission_types' => $types, 'sort_order' => 10]);
    $this->waiting = $this->set->questions()->create(['code' => 'waiting_time', 'section' => 'experience', 'question_type' => 'rating', 'label_en' => 'Waiting time', 'label_am' => 'የጥበቃ ጊዜ', 'is_required' => false, 'submission_types' => $types, 'sort_order' => 20]);
    $this->obtained = $this->set->questions()->create(['code' => 'service_obtained', 'section' => 'details', 'question_type' => 'yes_no', 'label_en' => 'Did you get the service?', 'label_am' => 'አገልግሎቱን አግኝተዋል?', 'is_required' => false, 'submission_types' => $types, 'sort_order' => 30]);
    $this->channels = $this->set->questions()->create(['code' => 'channels', 'section' => 'details', 'question_type' => 'multiple_choice', 'label_en' => 'How did you hear?', 'label_am' => 'እንዴት ሰሙ?', 'is_required' => false, 'submission_types' => $types, 'sort_order' => 40]);
    $this->website = $this->channels->options()->create(['code' => 'website', 'label_en' => 'Website', 'label_am' => 'ድረ-ገጽ', 'sort_order' => 1]);
    $this->radio = $this->channels->options()->create(['code' => 'radio', 'label_en' => 'Radio', 'label_am' => 'ሬዲዮ', 'sort_order' => 2]);
    $this->improvement = $this->set->questions()->create(['code' => 'improvement', 'section' => 'details', 'question_type' => 'long_text', 'label_en' => 'What should be improved?', 'label_am' => 'ምን መሻሻል አለበት?', 'is_required' => false, 'submission_types' => $types, 'sort_order' => 50]);
});

/** An organization-QR submission at one organization, answered on the form. */
function sqaAnswered(string $orgKey, string $type, array $ratings, ?bool $obtained = null, array $channels = [], array $extra = []): EmployeeServiceFeedback
{
    $t = test();
    $entry = $t->s->submission($t->s->org[$orgKey], $type, ['question_set_id' => $t->set->id] + $extra);
    $row = fn (PublicFeedbackQuestion $question, array $values): PublicFeedbackAnswer => PublicFeedbackAnswer::query()->create([
        'submission_id' => $entry->id, 'question_set_id' => $t->set->id, 'question_id' => $question->id,
        'question_code' => $question->code, 'question_type' => $question->question_type->value,
    ] + $values);

    foreach ($ratings as $code => $value) {
        $row($code === 'staff_courtesy' ? $t->courtesy : $t->waiting, ['rating_value' => $value]);
    }
    if ($obtained !== null) {
        $row($t->obtained, ['bool_value' => $obtained]);
    }
    foreach ($channels as $option) {
        $row($t->channels, ['option_id' => $option->id]);
    }
    $row($t->improvement, ['text_value' => 'Private written answer that must never be aggregated']);

    return $entry;
}

/** @return array<string, mixed> the deferred result of a category page */
function sqaResult(User $user, string $category, array $query = []): array
{
    $result = null;
    test()->actingAs($user)->get(route('situation-room.category', ['category' => $category] + $query))->assertOk()
        ->assertInertia(function (Assert $page) use (&$result): void {
            $page->component('SituationRoom/Category')->loadDeferredProps(function (Assert $reload) use (&$result): void {
                $result = $reload->toArray()['props']['result'];
            });
        });

    return $result;
}

/** @return array<string, mixed> */
function sqaPanel(array $result, string $key): array
{
    return collect($result['payload']['panels'])->firstWhere('key', $key) ?? throw new RuntimeException("Panel {$key} not in payload");
}

/** @return array<string, list<int>> question (en) => counts for points 1..5 */
function sqaDistribution(array $panel): array
{
    $rows = [];
    foreach ($panel['data']['categories'] as $i => $category) {
        $rows[$category['en']] = array_map(fn (array $series): int => (int) $series['values'][$i], $panel['data']['series']);
    }

    return $rows;
}

function sqaKpi(array $result, string $panel, string $key): mixed
{
    return collect(sqaPanel($result, $panel)['data']['items'])->firstWhere('key', $key)['value'];
}

it('rolls a woreda\'s structured answers up to its sub-city and bureau, and never into a sibling', function (string $type, string $prefix): void {
    sqaAnswered('woreda_a1', $type, ['staff_courtesy' => 5, 'waiting_time' => 2]);
    sqaAnswered('woreda_a1', $type, ['staff_courtesy' => 4]);
    sqaAnswered('woreda_a2', $type, ['staff_courtesy' => 1]);
    sqaAnswered('woreda_b1', $type, ['staff_courtesy' => 3, 'waiting_time' => 3]);

    // The Situation Room category of each type is named like its panel prefix.
    $views = [];
    foreach (['woreda_a1', 'subcity_a', 'bureau', 'subcity_b'] as $orgKey) {
        $views[$orgKey] = sqaResult($this->s->viewer([SituationPermissions::PUBLIC_VOICE], $orgKey), $prefix);
    }

    // In the questionnaire's order; one count per point of the scale.
    expect(sqaDistribution(sqaPanel($views['woreda_a1'], "{$prefix}_question_ratings")))->toBe([
        'Courtesy of the staff' => [0, 0, 0, 1, 1],
        'Waiting time' => [0, 1, 0, 0, 0],
    ])->and(sqaDistribution(sqaPanel($views['subcity_a'], "{$prefix}_question_ratings")))->toBe([
        'Courtesy of the staff' => [1, 0, 0, 1, 1],
        'Waiting time' => [0, 1, 0, 0, 0],
    ])->and(sqaDistribution(sqaPanel($views['bureau'], "{$prefix}_question_ratings")))->toBe([
        'Courtesy of the staff' => [1, 0, 1, 1, 1],
        'Waiting time' => [0, 1, 1, 0, 0],
    ])->and(sqaDistribution(sqaPanel($views['subcity_b'], "{$prefix}_question_ratings")))->toBe([
        'Courtesy of the staff' => [0, 0, 1, 0, 0],
        'Waiting time' => [0, 0, 1, 0, 0],
    ]);

    // The scale is labelled, never shown as bare numbers or stars, and drawn as one ordered scale.
    expect(array_column(sqaPanel($views['bureau'], "{$prefix}_question_ratings")['data']['series'], 'label'))
        ->toBe(array_map(fn (int $point): array => ['key' => "situationRoom.values.satisfaction.{$point}"], [1, 2, 3, 4, 5]))
        ->and(sqaPanel($views['bureau'], "{$prefix}_question_ratings")['data']['palette'])->toBe('diverging');
})->with([
    'feedback' => ['feedback', 'feedback'],
    'tip-off' => ['suggestion', 'tip_offs'],
    'complaint' => ['complaint', 'complaints'],
]);

it('counts choice answers per question with the share of those answering, and never aggregates written answers', function (): void {
    sqaAnswered('woreda_a1', 'feedback', [], true, [$this->website, $this->radio]);
    sqaAnswered('woreda_a1', 'feedback', [], true, [$this->website]);
    sqaAnswered('woreda_a2', 'feedback', [], false);

    $result = sqaResult($this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'subcity_a'), 'feedback');
    $rows = collect(sqaPanel($result, 'feedback_question_choices')['data']['rows'])
        ->map(fn (array $row): array => [$row['question']['en'], $row['answer']['en'] ?? $row['answer']['key'], $row['total'], $row['share']])
        ->all();

    expect($rows)->toEqual([
        ['Did you get the service?', 'situationRoom.values.yes_no.yes', 2, 66.7],
        ['Did you get the service?', 'situationRoom.values.yes_no.no', 1, 33.3],
        // Several answers allowed: the shares add up to more than 100%.
        ['How did you hear?', 'Website', 2, 100.0],
        ['How did you hear?', 'Radio', 1, 50.0],
    ]);

    $json = json_encode($result);
    expect($json)->not->toContain('Private written answer')
        ->not->toContain('What should be improved')
        ->not->toContain('Private Submitter')
        ->not->toContain('+251911000000')
        ->not->toContain('Private narrative');
});

it('keeps a sibling\'s choice answers out, and says there is no data rather than showing zeros', function (): void {
    sqaAnswered('woreda_b1', 'complaint', ['staff_courtesy' => 2], true);

    $result = sqaResult($this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'subcity_a'), 'complaints');

    expect(sqaPanel($result, 'complaints_question_ratings'))->toMatchArray(['type' => 'notice', 'reason' => 'NO_DATA'])
        ->and(sqaPanel($result, 'complaints_question_choices'))->toMatchArray(['type' => 'notice', 'reason' => 'NO_DATA']);
});

it('follows a reworded question across versions in its newest wording, without moving any answer', function (): void {
    $old = sqaAnswered('woreda_a1', 'feedback', ['staff_courtesy' => 5]);

    $this->set->update(['status' => PublicFeedbackQuestionSetStatus::Retired, 'retired_at' => now()]);
    $v2 = PublicFeedbackQuestionSet::query()->create([
        'code' => PublicFeedbackQuestionnaire::ORGANIZATION_FORM, 'version_no' => 2, 'name_en' => 'Form', 'name_am' => 'ቅጽ',
        'status' => PublicFeedbackQuestionSetStatus::Published, 'published_at' => now(),
    ]);
    $courtesy2 = $v2->questions()->create(['code' => 'staff_courtesy', 'section' => 'experience', 'question_type' => 'rating', 'label_en' => 'Respect shown by the staff', 'label_am' => 'ሠራተኞች ያሳዩት አክብሮት', 'is_required' => false, 'submission_types' => ['feedback'], 'sort_order' => 10]);
    $new = $this->s->submission($this->s->org['woreda_a1'], 'feedback', ['question_set_id' => $v2->id]);
    PublicFeedbackAnswer::query()->create(['submission_id' => $new->id, 'question_set_id' => $v2->id, 'question_id' => $courtesy2->id, 'question_code' => 'staff_courtesy', 'question_type' => 'rating', 'rating_value' => 3]);

    $result = sqaResult($this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'woreda_a1'), 'feedback');

    expect(sqaDistribution(sqaPanel($result, 'feedback_question_ratings'))['Respect shown by the staff'])->toBe([0, 0, 1, 0, 1])
        // The older answer still points at the version and wording it was given against.
        ->and(PublicFeedbackAnswer::query()->where('submission_id', $old->id)->where('question_code', 'staff_courtesy')->sole()->question_id)->toBe($this->courtesy->id)
        ->and($old->fresh()->question_set_id)->toBe($this->set->id);
});

it('leaves hidden submissions out of the question panels', function (): void {
    sqaAnswered('woreda_a1', 'feedback', ['staff_courtesy' => 5]);
    sqaAnswered('woreda_a1', 'feedback', ['staff_courtesy' => 1], extra: ['status' => 'hidden']);

    $result = sqaResult($this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'woreda_a1'), 'feedback');

    expect(sqaDistribution(sqaPanel($result, 'feedback_question_ratings'))['Courtesy of the staff'])->toBe([0, 0, 0, 0, 1]);
});

it('reconciles Feedback, Tip-offs and Complaints to every public submission in scope', function (): void {
    sqaAnswered('woreda_a1', 'feedback', ['staff_courtesy' => 5]);
    sqaAnswered('woreda_a2', 'feedback', ['staff_courtesy' => 4]);
    sqaAnswered('woreda_a1', 'suggestion', []);
    sqaAnswered('woreda_a2', 'complaint', ['waiting_time' => 1]);
    sqaAnswered('woreda_b1', 'feedback', ['staff_courtesy' => 2]);
    // Moderated out: in no view.
    sqaAnswered('woreda_a1', 'complaint', [], extra: ['status' => 'hidden']);

    $viewer = $this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'subcity_a');
    $feedback = sqaKpi(sqaResult($viewer, 'feedback'), 'feedback_kpis', 'feedback_received');
    $tipOffs = sqaKpi(sqaResult($viewer, 'tip_offs'), 'tip_offs_kpis', 'tip_offs_received');
    $complaints = sqaKpi(sqaResult($viewer, 'complaints'), 'complaints_kpis', 'complaints_received');

    $inScope = EmployeeServiceFeedback::query()
        ->whereIn('organization_id', [$this->s->org['subcity_a']->id, $this->s->org['woreda_a1']->id, $this->s->org['woreda_a2']->id])
        ->where('status', '!=', 'hidden')
        ->count();

    // The scenario's own complaints (6 in Subcity A's subtree) plus the ones made here.
    expect([$feedback, $tipOffs, $complaints])->toBe([2, 1, 7])
        ->and($feedback + $tipOffs + $complaints)->toBe($inScope);
});

it('counts an organization-form catalog service in the service panel, never a service given only in words', function (): void {
    $service = Service::query()->create([
        'organization_id' => $this->s->org['woreda_a1']->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1,
        'code' => 'SR-SVC', 'name_en' => 'Residence certificate', 'name_am' => 'የነዋሪነት ማረጋገጫ',
        'effective_from' => now()->subMonth()->toDateString(), 'status' => 'published',
    ]);
    sqaAnswered('woreda_a1', 'feedback', [], extra: ['service_id' => $service->id, 'service_no_snapshot' => 'SR-SVC', 'service_name_snapshot' => 'Residence certificate']);
    sqaAnswered('woreda_a1', 'feedback', [], extra: ['service_id' => $service->id]);
    sqaAnswered('woreda_a1', 'feedback', [], extra: ['service_text' => 'Something I could not find']);

    $panel = sqaPanel(sqaResult($this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'subcity_a'), 'feedback'), 'feedback_service');

    expect($panel['data']['categories'])->toBe([['en' => 'Residence certificate', 'am' => 'የነዋሪነት ማረጋገጫ']])
        ->and($panel['data']['series'][0]['values'])->toBe([2]);
});

it('keeps the submission on its woreda when the hierarchy changes, and rolls it up under the published structure', function (): void {
    sqaAnswered('woreda_a1', 'feedback', ['staff_courtesy' => 5]);

    // Woreda A1 moves under Subcity B in the published structure.
    $woreda = $this->s->org['woreda_a1']->id;
    OrganizationClosurePath::query()->where('descendant_organization_id', $woreda)->where('depth', '>', 0)->delete();
    OrganizationEdge::query()->where('child_organization_id', $woreda)->update(['parent_organization_id' => $this->s->org['subcity_b']->id]);
    foreach ([[$this->s->org['subcity_b'], 1], [$this->s->org['bureau'], 2]] as [$ancestor, $depth]) {
        OrganizationClosurePath::query()->create(['hierarchy_version_id' => $this->s->version->id, 'ancestor_organization_id' => $ancestor->id, 'descendant_organization_id' => $woreda, 'depth' => $depth]);
    }
    Cache::flush();

    $subcityA = sqaResult($this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'subcity_a'), 'feedback');
    $subcityB = sqaResult($this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'subcity_b'), 'feedback');

    expect(sqaPanel($subcityA, 'feedback_question_ratings')['reason'])->toBe('NO_DATA')
        ->and(sqaDistribution(sqaPanel($subcityB, 'feedback_question_ratings'))['Courtesy of the staff'])->toBe([0, 0, 0, 0, 1])
        // The row itself never moves: still the woreda it was given about.
        ->and(EmployeeServiceFeedback::query()->where('type', 'feedback')->sole()->organization_target_id)->toBe($this->s->org['woreda_a1']->id);
});
