<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/** A portal account; with $respondent, also the respondent record of the same person. */
function portalFeedPerson(bool $respondent = false): Applicant
{
    $suffix = Str::lower(Str::random(8));
    $applicant = Applicant::create([
        'first_name' => 'Hana', 'middle_name' => 'T', 'last_name' => 'Alemu', 'gender' => 'female',
        'position' => 'Officer', 'organization_name' => 'Example Org', 'phone' => '09'.random_int(10000000, 99999999),
        'email' => "portal-feed-{$suffix}@example.com", 'address' => 'Addis Ababa', 'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'password' => Hash::make('password'), 'is_active' => true, 'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    if ($respondent) {
        Respondent::create([
            'first_name' => 'Hana', 'middle_name' => 'T', 'last_name' => 'Alemu', 'gender' => 'female', 'position' => 'Officer',
            'organization_name' => 'Example Org', 'address' => 'Addis Ababa', 'phone' => $applicant->phone,
            'email' => $applicant->email, 'password' => Hash::make('password'),
        ]);
    }

    return $applicant;
}

/** A case of $owner with a hearing, two staff messages (one a letter link), the owner's own message and a status change. @return array{id: int, number: string, hearing: int, message: int, letter: int, status: int} */
function portalFeedCase(Applicant $owner): array
{
    $staff = User::factory()->create();
    $typeId = DB::table('court_case_types')->insertGetId(['name' => 'Civil '.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    $number = 'CASE-PF-'.Str::upper(Str::random(6));
    $id = DB::table('court_cases')->insertGetId([
        'applicant_id' => $owner->id, 'case_number' => $number, 'code' => 'PF'.random_int(1000, 9999), 'title' => 'Land dispute',
        'case_type_id' => $typeId, 'filing_date' => now()->toDateString(), 'status' => 'active', 'review_status' => 'accepted',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'id' => $id,
        'number' => $number,
        'hearing' => DB::table('court_case_hearings')->insertGetId(['case_id' => $id, 'hearing_at' => now()->addDays(3), 'type' => 'Preliminary', 'location' => 'Courtroom 2', 'created_at' => now(), 'updated_at' => now()]),
        'message' => DB::table('court_case_messages')->insertGetId(['case_id' => $id, 'sender_user_id' => $staff->id, 'body' => 'Please bring the original documents.', 'created_at' => now()->subMinutes(5), 'updated_at' => now()]),
        'letter' => DB::table('court_case_messages')->insertGetId(['case_id' => $id, 'sender_user_id' => $staff->id, 'body' => 'https://court.example/case-letters/5', 'created_at' => now()->subMinutes(6), 'updated_at' => now()]),
        'status' => tap(DB::table('court_case_status_logs')->insertGetId(['case_id' => $id, 'from_status' => 'pending', 'to_status' => 'active', 'created_at' => now()->subMinutes(7), 'updated_at' => now()]), function () use ($id, $owner): void {
            // The applicant's own message is not a notification to them.
            DB::table('court_case_messages')->insert(['case_id' => $id, 'sender_applicant_id' => $owner->id, 'body' => 'Noted.', 'created_at' => now(), 'updated_at' => now()]);
        }),
    ];
}

test('the applicant bell lists hearings, court messages, status changes and respondent views of their own cases', function (): void {
    $applicant = portalFeedPerson();
    $case = portalFeedCase($applicant);
    portalFeedCase(portalFeedPerson()); // someone else's case stays out

    $viewer = portalFeedPerson(respondent: true);
    $viewId = DB::table('court_respondent_case_views')->insertGetId([
        'respondent_id' => Respondent::where('email', $viewer->email)->value('id'), 'case_id' => $case['id'],
        'case_number' => $case['number'], 'viewed_at' => now()->subMinutes(8), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $feed = $this->actingAs($applicant, 'applicant')->getJson(route('court.applicant.notifications.feed'))
        ->assertOk()
        ->assertJsonPath('unread_count', 5)
        ->assertJsonPath('page', 1)
        ->assertJsonPath('last_page', 1)
        ->json('items');

    $byId = collect($feed)->keyBy('id');
    expect($byId->keys()->sort()->values()->all())->toBe(collect([
        "hearing:{$case['hearing']}", "message:{$case['message']}", "message:{$case['letter']}", "status:{$case['status']}", "respondent_view:{$viewId}",
    ])->sort()->values()->all());
    expect($byId["hearing:{$case['hearing']}"]['url'])->toBe(route('court.applicant.cases.show', $case['id']))
        ->and($byId["hearing:{$case['hearing']}"]['title'])->toContain($case['number'])
        ->and($byId["hearing:{$case['hearing']}"]['message'])->toContain('Courtroom 2')
        ->and($byId["message:{$case['message']}"]['message'])->toBe('Please bring the original documents.')
        // A letter link opens the letter.
        ->and($byId["message:{$case['letter']}"]['url'])->toBe('https://court.example/case-letters/5')
        ->and($byId["respondent_view:{$viewId}"]['message'])->toContain('Hana T Alemu')
        ->and(collect($feed)->every(fn (array $item): bool => $item['read'] === false))->toBeTrue();

    // One read, then all read; the feed and the notifications page share the seen rows.
    $this->postJson(route('court.applicant.notifications.read', "hearing:{$case['hearing']}"))->assertOk();
    $this->getJson(route('court.applicant.notifications.feed'))->assertJsonPath('unread_count', 4);
    expect(DB::table('court_notification_reads')->where(['applicant_id' => $applicant->id, 'type' => 'hearing', 'source_id' => $case['hearing']])->exists())->toBeTrue();

    $this->postJson(route('court.applicant.notifications.read-all'))->assertOk();
    $this->getJson(route('court.applicant.notifications.feed'))->assertJsonPath('unread_count', 0)->assertJsonPath('items', []);
    $this->get(route('court.applicant.notifications.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Court/Applicant/Notifications')
        ->where('hearings.data', [])
        ->where('messages.data', [])
        ->where('statusChanges.data', [])
        ->where('respondentViews.data', []));

    // Marking read again is harmless; a malformed id is not found.
    $this->postJson(route('court.applicant.notifications.read-all'))->assertOk();
    $this->postJson(route('court.applicant.notifications.read', 'case:1'))->assertNotFound();
    $this->postJson(route('court.applicant.notifications.read', 'hearing:abc'))->assertNotFound();
});

test('the respondent bell lists what happened on the cases the respondent opened', function (): void {
    $person = portalFeedPerson(respondent: true);
    $respondentId = (int) Respondent::where('email', $person->email)->value('id');
    $case = portalFeedCase(portalFeedPerson());
    portalFeedCase(portalFeedPerson()); // a case this respondent never opened stays out
    $viewId = DB::table('court_respondent_case_views')->insertGetId([
        'respondent_id' => $respondentId, 'case_id' => $case['id'], 'case_number' => $case['number'],
        'viewed_at' => now()->subMinutes(8), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $items = $this->actingAs($person, 'applicant')->getJson(route('court.respondent.notifications.feed'))
        ->assertOk()
        ->assertJsonPath('unread_count', 5)
        ->json('items');

    $byId = collect($items)->keyBy('id');
    expect($byId->keys()->sort()->values()->all())->toBe(collect([
        "hearing:{$case['hearing']}", "message:{$case['message']}", "message:{$case['letter']}", "status:{$case['status']}", "respondent_view:{$viewId}",
    ])->sort()->values()->all())
        ->and($byId["status:{$case['status']}"]['url'])->toBe(route('court.respondent.cases.show', $case['number']))
        ->and($byId["respondent_view:{$viewId}"]['message'])->toBe('Land dispute');

    $this->postJson(route('court.respondent.notifications.read', "status:{$case['status']}"))->assertOk();
    expect(DB::table('court_respondent_notification_reads')->where(['respondent_id' => $respondentId, 'type' => 'status', 'source_id' => $case['status']])->exists())->toBeTrue();
    $this->getJson(route('court.respondent.notifications.feed'))->assertJsonPath('unread_count', 4);

    $this->postJson(route('court.respondent.notifications.read-all'))->assertOk();
    $this->getJson(route('court.respondent.notifications.feed'))->assertJsonPath('unread_count', 0);
    // The applicant side of the same person keeps its own seen rows.
    expect(DB::table('court_notification_reads')->where('applicant_id', $person->id)->count())->toBe(0);

    // The respondent dashboard's panel agrees with the bell.
    $this->get(route('court.respondent.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('notifications.count', 0));
});

test('a person with no respondent record sees an empty respondent bell and cannot mark anything', function (): void {
    $person = portalFeedPerson();

    $this->actingAs($person, 'applicant')->getJson(route('court.respondent.notifications.feed'))
        ->assertOk()
        ->assertExactJson(['unread_count' => 0, 'items' => [], 'page' => 1, 'last_page' => 1]);
    $this->postJson(route('court.respondent.notifications.read-all'))->assertForbidden();
});

test('the portal header gets its bell routes and guests cannot read a portal feed', function (): void {
    $person = portalFeedPerson(respondent: true);

    $this->actingAs($person, 'applicant')->get(route('court.applicant.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('portal.feed', 'court.applicant.notifications.feed')
        ->where('portal.read', 'court.applicant.notifications.read')
        ->where('portal.readAll', 'court.applicant.notifications.read-all')
        ->where('portal.viewAll', 'court.applicant.notifications.index'));
    $this->get(route('court.respondent.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('portal.feed', 'court.respondent.notifications.feed')
        ->where('portal.viewAll', 'court.respondent.dashboard'));

    auth('applicant')->logout();
    $this->getJson(route('court.applicant.notifications.feed'))->assertUnauthorized();
    $this->getJson(route('court.respondent.notifications.feed'))->assertUnauthorized();
});
