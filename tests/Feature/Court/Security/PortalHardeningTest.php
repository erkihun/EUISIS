<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\Notifications\PortalNotificationFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
| Fixes #3 made in the applicant and respondent portals (58e8368, 59726fe, 1059d39),
| ported to these pages: the case access code stays out of URLs, accepted responses
| and accepted cases cannot change, and only http(s) message bodies become links.
*/

function portalHardeningPerson(bool $respondent = true): Applicant
{
    $suffix = Str::lower(Str::random(8));
    $applicant = Applicant::create([
        'first_name' => 'Abel', 'middle_name' => 'T', 'last_name' => 'Kebede', 'gender' => 'male',
        'position' => 'Officer', 'organization_name' => 'Org', 'phone' => '09'.random_int(10000000, 99999999),
        'email' => "portal-hardening-{$suffix}@example.com", 'address' => 'Addis Ababa',
        'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'password' => Hash::make('password'), 'is_active' => true, 'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();
    if ($respondent) {
        Respondent::create([
            'first_name' => 'Abel', 'middle_name' => 'T', 'last_name' => 'Kebede', 'gender' => 'male', 'position' => 'Officer',
            'organization_name' => 'Org', 'address' => 'Addis Ababa', 'phone' => $applicant->phone,
            'email' => $applicant->email, 'password' => Hash::make('password'),
        ]);
    }

    return $applicant;
}

/** @return array{id: int, number: string, code: string} */
function portalHardeningCase(Applicant $owner, array $extra = []): array
{
    $typeId = DB::table('court_case_types')->insertGetId(['name' => 'Civil '.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    $number = 'CASE-PH-'.Str::upper(Str::random(6));
    $code = 'PH'.random_int(1000, 9999);
    $id = DB::table('court_cases')->insertGetId([
        'applicant_id' => $owner->id, 'case_number' => $number, 'code' => $code, 'title' => 'Land dispute',
        'case_type_id' => $typeId, 'filing_date' => now()->toDateString(), 'status' => 'pending', 'review_status' => 'accepted',
        'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);

    return ['id' => $id, 'number' => $number, 'code' => $code];
}

test('the case search posts, redirects without the access code and opens the case without it', function (): void {
    $person = portalHardeningPerson();
    $case = portalHardeningCase(portalHardeningPerson(false));

    $response = $this->actingAs($person, 'applicant')->post(route('court.respondent.case.search.submit'), ['case_number' => $case['number'], 'case_code' => $case['code']]);
    $response->assertRedirect(route('court.respondent.case.search', ['case_number' => $case['number']]));
    expect($response->headers->get('Location'))->not->toContain($case['code']);

    $this->get(route('court.respondent.case.search', ['case_number' => $case['number']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/CaseSearch')
            ->where('case.case_number', $case['number'])
            ->where('case.show_url', route('court.respondent.cases.show', ['caseNumber' => $case['number']]))
            ->missing('caseCode'));

    // The recorded view opens the case page and the "My cases" link, neither with the code.
    $this->get(route('court.respondent.cases.show', ['caseNumber' => $case['number']]))->assertOk();
    $this->get(route('court.respondent.cases.my'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('cases.0.show_url', fn (string $url): bool => ! str_contains($url, $case['code'])));
});

test('a wrong access code is reported after the redirect and grants nothing', function (): void {
    $person = portalHardeningPerson();
    $case = portalHardeningCase(portalHardeningPerson(false));

    $this->actingAs($person, 'applicant')->post(route('court.respondent.case.search.submit'), ['case_number' => $case['number'], 'case_code' => 'WRONG1'])
        ->assertRedirect(route('court.respondent.case.search', ['case_number' => $case['number']]));
    $this->get(route('court.respondent.case.search', ['case_number' => $case['number']]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('case', null)->where('codeError', true));
    $this->assertDatabaseCount('court_respondent_case_views', 0);
    $this->get(route('court.respondent.cases.show', ['caseNumber' => $case['number']]))->assertNotFound();
});

test('the case search allows ten tries a minute', function (): void {
    $person = portalHardeningPerson();
    $case = portalHardeningCase(portalHardeningPerson(false));

    foreach (range(1, 10) as $try) {
        $this->actingAs($person, 'applicant')->post(route('court.respondent.case.search.submit'), ['case_number' => $case['number'], 'case_code' => 'WRONG'.$try])->assertRedirect();
    }
    $this->actingAs($person, 'applicant')->post(route('court.respondent.case.search.submit'), ['case_number' => $case['number'], 'case_code' => $case['code']])->assertStatus(429);
});

test('an accepted response cannot be edited or deleted, and an edited one goes back to review', function (): void {
    $person = portalHardeningPerson();
    $respondentId = (int) Respondent::where('email', $person->email)->value('id');
    $case = portalHardeningCase(portalHardeningPerson(false));
    DB::table('court_respondent_case_views')->insert(['respondent_id' => $respondentId, 'case_id' => $case['id'], 'case_number' => $case['number'], 'viewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $reviewer = User::factory()->create();

    $accepted = RespondentResponse::create(['respondent_id' => $respondentId, 'case_number' => $case['number'], 'title' => 'Accepted answer', 'pdf_path' => 'respondent/responses/a.pdf', 'review_status' => 'accepted', 'reviewed_by_user_id' => $reviewer->id, 'reviewed_at' => now()]);
    $this->actingAs($person, 'applicant')->get(route('court.respondent.responses.edit', $accepted))->assertRedirect(route('court.respondent.responses.show', $accepted));
    $this->patch(route('court.respondent.responses.update', $accepted), ['title' => 'Changed', 'case_number' => $case['number']])->assertForbidden();
    $this->delete(route('court.respondent.responses.destroy', $accepted))->assertForbidden();
    expect($accepted->fresh()->title)->toBe('Accepted answer');

    $returned = RespondentResponse::create(['respondent_id' => $respondentId, 'case_number' => $case['number'], 'title' => 'Returned answer', 'pdf_path' => 'respondent/responses/b.pdf', 'review_status' => 'returned', 'review_note' => 'Add the annex', 'reviewed_by_user_id' => $reviewer->id, 'reviewed_at' => now()]);
    $this->patch(route('court.respondent.responses.update', $returned), ['title' => 'Corrected answer', 'case_number' => $case['number'], 'description' => '<p>With the annex</p><script>alert(1)</script>'])->assertRedirect();
    $fresh = $returned->fresh();
    expect($fresh->review_status)->toBe('awaiting_review')
        ->and($fresh->review_note)->toBeNull()
        ->and($fresh->reviewed_by_user_id)->toBeNull()
        ->and($fresh->reviewed_at)->toBeNull();

    // The description is cleaned again on the way out, even if a stored one slipped past the save.
    DB::table('court_respondent_responses')->where('id', $returned->id)->update(['description' => '<p>Stored</p><script>alert(2)</script>']);
    $this->get(route('court.respondent.responses.show', $returned))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('response.description', fn (string $html): bool => str_contains($html, 'Stored') && ! str_contains($html, 'script')));
});

test('respondent mark-one accepts only known notice types and mark-all twice is harmless', function (): void {
    $person = portalHardeningPerson();

    $this->actingAs($person, 'applicant')->from(route('court.respondent.dashboard'))
        ->post(route('court.respondent.notifications.markOne'), ['type' => 'anything-at-all', 'sourceId' => 5])
        ->assertSessionHasErrors('type');
    $this->assertDatabaseCount('court_respondent_notification_reads', 0);

    $this->from(route('court.respondent.dashboard'))->post(route('court.respondent.notifications.markOne'), ['type' => 'hearing', 'sourceId' => 5])->assertRedirect();
    $this->assertDatabaseCount('court_respondent_notification_reads', 1);

    $this->from(route('court.respondent.dashboard'))->post(route('court.respondent.notifications.markAll'))->assertRedirect();
    $this->from(route('court.respondent.dashboard'))->post(route('court.respondent.notifications.markAll'))->assertRedirect();
});

test('a message body becomes a link only when it is an http(s) address', function (): void {
    $applicant = portalHardeningPerson(false);
    $case = portalHardeningCase($applicant);
    $staff = User::factory()->create();
    DB::table('court_case_messages')->insert([
        ['case_id' => $case['id'], 'sender_user_id' => $staff->id, 'body' => 'javascript://example.com/%0Aalert(1)', 'created_at' => now(), 'updated_at' => now()],
        ['case_id' => $case['id'], 'sender_user_id' => $staff->id, 'body' => 'https://court.example/case-letters/7', 'created_at' => now()->subMinute(), 'updated_at' => now()],
    ]);

    $urls = collect(PortalNotificationFeed::forApplicant((int) $applicant->id)->feed(1)['items'])->pluck('url');
    expect($urls)->toContain('https://court.example/case-letters/7')
        ->and($urls->filter(fn (?string $url): bool => $url !== null && str_starts_with($url, 'javascript')))->toBeEmpty();

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.notifications.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('messages.data', fn ($messages): bool => collect($messages)->pluck('url')->filter()->values()->all() === ['https://court.example/case-letters/7']));
});

test('an applicant cannot remove evidence or witnesses once the case is accepted', function (): void {
    $applicant = portalHardeningPerson(false);
    $case = portalHardeningCase($applicant, ['status' => 'pending', 'review_status' => 'accepted']);
    $evidenceId = DB::table('court_case_evidences')->insertGetId(['case_id' => $case['id'], 'type' => 'document', 'title' => 'Contract', 'created_at' => now(), 'updated_at' => now()]);
    $witnessId = DB::table('court_case_witnesses')->insertGetId(['case_id' => $case['id'], 'full_name' => 'Witness One', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($applicant, 'applicant')->from(route('court.applicant.cases.show', $case['id']))
        ->delete(route('court.applicant.cases.evidences.delete', [$case['id'], $evidenceId]))->assertSessionHas('error');
    $this->from(route('court.applicant.cases.show', $case['id']))
        ->delete(route('court.applicant.cases.witnesses.delete', [$case['id'], $witnessId]))->assertSessionHas('error');
    $this->assertDatabaseHas('court_case_evidences', ['id' => $evidenceId]);
    $this->assertDatabaseHas('court_case_witnesses', ['id' => $witnessId]);

    // While it is still awaiting review, removing works.
    DB::table('court_cases')->where('id', $case['id'])->update(['review_status' => 'awaiting_review']);
    $this->from(route('court.applicant.cases.show', $case['id']))
        ->delete(route('court.applicant.cases.witnesses.delete', [$case['id'], $witnessId]))->assertSessionHas('success', __('court::cases.messages_flash.witness_removed'));
    $this->assertDatabaseMissing('court_case_witnesses', ['id' => $witnessId]);
});

test('a respondent reopening a case tells the applicant and court at most once a day', function (): void {
    \Illuminate\Support\Facades\Mail::fake();
    \Illuminate\Support\Facades\Http::fake();
    $person = portalHardeningPerson();
    $case = portalHardeningCase(portalHardeningPerson(false));

    $this->actingAs($person, 'applicant')->post(route('court.respondent.case.search.submit'), ['case_number' => $case['number'], 'case_code' => $case['code']]);
    $this->get(route('court.respondent.cases.show', ['caseNumber' => $case['number']]))->assertOk();
    $sent = \Illuminate\Support\Facades\Mail::sent(\Modules\Court\Application\Mail\RespondentViewedCaseMail::class)->count();
    expect($sent)->toBeGreaterThan(0);

    $this->get(route('court.respondent.cases.show', ['caseNumber' => $case['number']]))->assertOk();
    $this->get(route('court.respondent.cases.show', ['caseNumber' => $case['number']]))->assertOk();

    expect(\Illuminate\Support\Facades\Mail::sent(\Modules\Court\Application\Mail\RespondentViewedCaseMail::class)->count())->toBe($sent);
    // Every view is still recorded.
    $this->assertDatabaseHas('court_respondent_case_views', ['case_id' => $case['id']]);
});
