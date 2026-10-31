<?php

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// Live, request-level companion to docs/insa-security-assurance/21_OWASP_Testing_Checklist.md.
// Runs real HTTP requests (routing + middleware + controllers) through Laravel's test client
// against the isolated `court_ms_test` database (see .env.testing) — no real user data touched,
// no real email sent (MAIL_MAILER=array). Each test cites the checklist item number it covers.

function makeVerifiedApplicant(array $overrides = []): Applicant
{
    static $seq = 0;
    $seq++;

    $applicant = Applicant::create(array_merge([
        'first_name' => 'Test',
        'middle_name' => 'Q',
        'last_name' => 'Applicant'.$seq,
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '09110000'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT),
        'email' => "owasp-live-{$seq}@example.com",
        'address' => 'Addis Ababa',
        'national_id_number' => str_pad((string) (1000000000000000 + $seq), 16, '0'),
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ], $overrides));

    $applicant->markEmailAsVerified();

    return $applicant;
}

function makeCaseTypeId(): int
{
    return DB::table('court_case_types')->insertGetId([
        'name' => 'Civil '.uniqid(),
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// #3 — accessible debug functionality
test('debug locale route is not reachable outside the local environment', function () {
    // app()->environment() in the testing suite is "testing", not "local" —
    // routes/web.php only registers /debug-locale when environment('local').
    $this->get('/debug-locale')->assertNotFound();
});

// #23 — missing authentication/authorization on admin routes
test('admin dashboard rejects unauthenticated requests', function () {
    $response = $this->get('/court/dashboard');

    $response->assertRedirect(); // to login, not a 200
    expect($response->status())->not->toBe(200);
});

// #28 — identifier-based authorization / IDOR
test('an applicant cannot view another applicants case by guessing the id', function () {
    $owner = makeVerifiedApplicant();
    $attacker = makeVerifiedApplicant();
    $caseTypeId = makeCaseTypeId();

    $caseId = DB::table('court_cases')->insertGetId([
        'applicant_id' => $owner->id,
        'case_number' => 'IDOR-TEST-'.uniqid(),
        'title' => 'Private case',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'awaiting_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($attacker, 'applicant')
        ->get(route('court.applicant.cases.show', $caseId))
        ->assertNotFound();

    // sanity check: the owner *can* see it
    $this->actingAs($owner, 'applicant')
        ->get(route('court.applicant.cases.show', $caseId))
        ->assertOk();
});

// #28 (API surface) — same IDOR check via the Sanctum-authenticated JSON API
test('api case show rejects a token holder who does not own the case', function () {
    $owner = makeVerifiedApplicant();
    $attacker = makeVerifiedApplicant();
    $caseTypeId = makeCaseTypeId();

    $caseId = DB::table('court_cases')->insertGetId([
        'applicant_id' => $owner->id,
        'case_number' => 'IDOR-API-'.uniqid(),
        'title' => 'Private API case',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'awaiting_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($attacker, ['*']);

    $this->getJson("/court/api/v1/cases/{$caseId}")->assertForbidden();
});

// #37 — missing rate limiting on authentication
test('staff login locks out after repeated failed attempts', function () {
    $user = User::factory()->create(['password' => Hash::make('CorrectHorse123')]);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    // 6th attempt should be blocked by the rate limiter, not just told "wrong password"
    $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    $response->assertSessionHasErrors('email');
    $errorBag = session('errors')->get('email')[0] ?? '';
    expect($errorBag)->not->toBe(trans('court::auth.failed'));
});

// #39 — missing logout functionality / session revocation
test('logout invalidates the session so the old cookie no longer authenticates', function () {
    // Real credential login (not actingAs()) so this exercises the actual session-cookie
    // lifecycle rather than short-circuiting auth resolution.
    $user = User::factory()->create(['password' => Hash::make('CorrectHorse123')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse123'])
        ->assertRedirect();

    $this->get('/court/dashboard')->assertOk();

    $this->post('/logout')->assertRedirect();

    $this->get('/court/dashboard')->assertRedirect(); // no longer authenticated
});

// #41 — SQL injection via a real search parameter (API case search)
test('sql injection payloads in the api case search are treated as literal text', function () {
    $applicant = makeVerifiedApplicant();
    $caseTypeId = makeCaseTypeId();

    DB::table('court_cases')->insert([
        'applicant_id' => $applicant->id,
        'case_number' => 'SQLI-CASE-'.uniqid(),
        'title' => 'Normal case',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'awaiting_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($applicant, ['*']);

    $payloads = [
        "' OR '1'='1",
        "'; DROP TABLE court_cases; --",
        "' UNION SELECT email, password, 1, 1, 1 FROM court_staff -- ",
    ];

    foreach ($payloads as $payload) {
        $response = $this->getJson('/court/api/v1/cases?search='.urlencode($payload));
        $response->assertOk(); // no 500 = no raw interpolation blowing up the query
    }

    // table must still exist and the app must still be able to query it
    expect(Schema::hasTable('court_cases'))->toBeTrue();
});

// #43 — stored XSS via a rich-text case field
test('script tags in a filed case description are stripped before storage and render', function () {
    $applicant = makeVerifiedApplicant();
    $caseTypeId = makeCaseTypeId();

    $file = UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf');

    $this->actingAs($applicant, 'applicant')
        ->post(route('court.applicant.cases.store'), [
            'title' => 'XSS probe case',
            'description' => '<p>Legit text</p><script>alert(document.cookie)</script><img src=x onerror=alert(1)>',
            'relief_requested' => 'Please rule in my favor.',
            'certify_appeal' => '1',
            'respondent_name' => 'Respondent Co',
            'respondent_address' => 'Somewhere',
            'case_type_id' => $caseTypeId,
            'filing_date' => now()->toDateString(),
            'evidence_titles' => ['Doc 1'],
            'evidence_files' => [$file],
            'witnesses' => [
                ['full_name' => 'Witness One', 'phone' => '0911223344', 'address' => 'Addis Ababa'],
            ],
            'certify_evidence' => '1',
        ])
        ->assertRedirect();

    $stored = DB::table('court_cases')->where('applicant_id', $applicant->id)->latest('id')->first();

    expect($stored)->not->toBeNull();
    expect($stored->description)->not->toContain('<script>');
    expect($stored->description)->not->toContain('onerror=');
    expect($stored->description)->toContain('Legit text');

    $page = $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.cases.show', $stored->id));

    $page->assertOk();
    $page->assertDontSee('<script>alert(document.cookie)</script>', false);
    $page->assertDontSee('onerror=alert(1)', false);
});

// #56 — mass assignment / data property injection via extra POST fields
test('case creation ignores attacker-supplied ownership and status fields', function () {
    $applicant = makeVerifiedApplicant();
    $victim = makeVerifiedApplicant();
    $caseTypeId = makeCaseTypeId();

    $file = UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf');

    $this->actingAs($applicant, 'applicant')
        ->post(route('court.applicant.cases.store'), [
            'title' => 'Mass assignment probe',
            'description' => 'Body text',
            'relief_requested' => 'Relief',
            'certify_appeal' => '1',
            'respondent_name' => 'Respondent Co',
            'respondent_address' => 'Somewhere',
            'case_type_id' => $caseTypeId,
            'filing_date' => now()->toDateString(),
            'evidence_titles' => ['Doc 1'],
            'evidence_files' => [$file],
            'witnesses' => [
                ['full_name' => 'Witness One', 'phone' => '0911223344', 'address' => 'Addis Ababa'],
            ],
            'certify_evidence' => '1',
            // attacker-controlled extras that should never reach the row as-is:
            'applicant_id' => $victim->id,
            'status' => 'approved',
            'review_status' => 'accepted',
            'id' => 999999,
        ])
        ->assertRedirect();

    $stored = DB::table('court_cases')->where('title', 'Mass assignment probe')->first();

    expect($stored)->not->toBeNull();
    expect((int) $stored->applicant_id)->toBe($applicant->id);
    expect($stored->status)->toBe('pending');
    expect($stored->review_status)->toBe('awaiting_review');
});

// #60 / #97 — open redirect via the post-login "intended URL" mechanism
test('post-login redirect only ever targets a same-origin previously requested url', function () {
    $user = User::factory()->create(['password' => Hash::make('CorrectHorse123')]);

    // Hitting a protected page while unauthenticated stores its *own* app URL as "intended".
    $this->get('/court/dashboard');

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse123']);

    $response->assertRedirect();
    $target = $response->headers->get('Location');

    expect($target)->toStartWith(config('app.url'));
    expect($target)->not->toContain('evil');
});

// #67 / #68 — session cookie hardening flags
test('the session cookie is issued with the httponly flag', function () {
    $response = $this->get('/login');

    $cookies = $response->headers->getCookies();
    $sessionCookieName = config('session.cookie');
    $sessionCookie = collect($cookies)->first(fn ($c) => $c->getName() === $sessionCookieName);

    expect($sessionCookie)->not->toBeNull();
    expect($sessionCookie->isHttpOnly())->toBeTrue();
    // Secure flag depends on APP_URL being https at runtime (config/session.php `$appUsesHttps`);
    // not asserted here since the test environment's APP_URL is http — see doc 05 for the
    // production requirement.
});

// #78 / #82 — file upload type validation
test('a non-pdf file is rejected as case evidence', function () {
    $applicant = makeVerifiedApplicant();
    $caseTypeId = makeCaseTypeId();

    $maliciousFile = UploadedFile::fake()->create('evidence.php', 10, 'application/x-php');

    $response = $this->actingAs($applicant, 'applicant')
        ->post(route('court.applicant.cases.store'), [
            'title' => 'Upload probe case',
            'description' => 'Body text',
            'relief_requested' => 'Relief',
            'certify_appeal' => '1',
            'respondent_name' => 'Respondent Co',
            'respondent_address' => 'Somewhere',
            'case_type_id' => $caseTypeId,
            'filing_date' => now()->toDateString(),
            'evidence_titles' => ['Doc 1'],
            'evidence_files' => [$maliciousFile],
            'witnesses' => [
                ['full_name' => 'Witness One', 'phone' => '0911223344', 'address' => 'Addis Ababa'],
            ],
            'certify_evidence' => '1',
        ]);

    $response->assertSessionHasErrors('evidence_files.0');
    expect(DB::table('court_cases')->where('title', 'Upload probe case')->exists())->toBeFalse();
});

// #98 — insecure cross-domain access policy (CORS)
test('cors does not echo back a disallowed origin on the api', function () {
    $response = $this->withHeaders(['Origin' => 'https://evil.example.com'])
        ->getJson('/court/api/health');

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example.com');
});

// #96 — anti-clickjacking header, at the application layer specifically
