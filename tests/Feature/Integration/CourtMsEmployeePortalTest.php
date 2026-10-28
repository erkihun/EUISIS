<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/*
| My Portal > My court cases (court-ms Flow 4, ADR 0013): the employee's tribunal
| cases read from court-ms, and a one-time link that opens the court portal
| signed in as them.
*/

function courtPortalEmployee(array $attributes = []): array
{
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-CRT-'.random_int(1000, 9999), 'first_name' => 'Meron', 'middle_name' => 'A', 'last_name' => 'Haile',
        'full_name' => 'Meron A Haile', 'gender' => 'female', 'status' => EmployeeStatus::Active,
        'email' => 'meron-'.random_int(1000, 9999).'@example.test', 'phone' => '0911'.random_int(100000, 999999),
        ...$attributes,
    ]);
    $employee->national_id = '1234567890123456';
    $employee->save();
    $user = User::factory()->create(['status' => 'active', 'employee_id' => $employee->id]);

    return [$employee, $user];
}

beforeEach(function (): void {
    config([
        'court_ms.enabled' => true, 'court_ms.url' => 'https://court.test', 'court_ms.token' => 'cms_test-token',
        'court_ms.employee_portal' => true,
    ]);
});

test('my court cases shows the employee\'s cases read from court-ms', function (): void {
    [$employee, $user] = courtPortalEmployee();
    Http::fake(['https://court.test/api/v1/integration/people/court-summary' => Http::response(['data' => [
        'linked' => true,
        'counts' => ['cases' => 1, 'open' => 1, 'awaiting_review' => 0, 'upcoming_hearings' => 1, 'unread_notices' => 2],
        'cases' => [['id' => 7, 'case_number' => 'CIV-00007-26', 'title' => 'Meron A Haile', 'respondent' => 'Bureau of Finance', 'case_type' => 'Civil', 'status' => 'active', 'review_status' => 'accepted', 'filed_at' => now()->toIso8601String(), 'next_hearing_at' => now()->addDays(3)->toIso8601String()]],
        'notices' => [['id' => 'hearing:3', 'title' => 'Hearing: CIV-00007-26', 'message' => 'Preliminary · Room 1', 'created_at' => now()->toIso8601String()]],
    ]])]);

    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Employee/CourtCases')
            ->where('state', 'linked')
            ->where('summary.counts.unread_notices', 2)
            ->where('summary.cases.0.case_number', 'CIV-00007-26')
            ->where('summary.cases.0.respondent', 'Bureau of Finance')
            ->where('features.court_portal', true));

    // court-ms is asked with the employee's UUID and national ID, by POST, so the ID is never in a URL.
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request['employee']['uuid'] === (string) $employee->id
        && $request['employee']['national_id'] === '1234567890123456'
        // court-ms words the notices in the language the page is read in.
        && in_array($request['locale'], ['en', 'am'], true)
        && $request->hasHeader('Authorization', 'Bearer cms_test-token'));
});

test('an employee without a court account is told the first open creates it, and court-ms down is said plainly', function (): void {
    [, $user] = courtPortalEmployee();
    Http::fake(['https://court.test/api/v1/integration/people/court-summary' => Http::sequence()
        ->push(['data' => ['linked' => false]])
        ->push(['message' => 'down'], 503)]);

    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'not_linked')->where('summary', null));

    cache()->flush();
    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'unavailable'));
});

test('opening the court portal asks court-ms for a sign-in link with the employee record', function (): void {
    [$employee, $user] = courtPortalEmployee();
    Http::fake(['https://court.test/api/v1/integration/people/sign-in-tickets' => Http::response(['data' => ['url' => 'https://court.test/applicant/euisis/sign-in/'.str_repeat('a', 64), 'expires_at' => now()->addMinutes(2)->toIso8601String()]], 201)]);

    $this->actingAs($user)->postJson(route('employee.court-cases.open'), ['target' => 'new_case'])
        ->assertOk()->assertJsonPath('url', 'https://court.test/applicant/euisis/sign-in/'.str_repeat('a', 64));

    Http::assertSent(fn (HttpRequest $request): bool => $request['target'] === 'new_case'
        && $request['employee']['first_name'] === 'Meron'
        && $request['employee']['last_name'] === 'Haile'
        && $request['employee']['phone'] === $employee->phone
        && $request['employee']['email'] === $employee->email);

    // A plain visit is sent straight there.
    $this->actingAs($user)->post(route('employee.court-cases.open'), ['target' => 'dashboard'])
        ->assertRedirect('https://court.test/applicant/euisis/sign-in/'.str_repeat('a', 64));
});

test('a refusal from court-ms is explained, and a link to another site is never followed', function (): void {
    [, $user] = courtPortalEmployee();
    Http::fake(['https://court.test/api/v1/integration/people/sign-in-tickets' => Http::sequence()
        ->push(['message' => 'incomplete', 'error_code' => 'employee_record_incomplete'], 409)
        ->push(['data' => ['url' => 'https://evil.test/phish']], 201)]);

    $this->actingAs($user)->postJson(route('employee.court-cases.open'), ['target' => 'new_case'])
        ->assertStatus(409)->assertJsonPath('error_code', 'employee_record_incomplete');
    $this->actingAs($user)->postJson(route('employee.court-cases.open'), ['target' => 'new_case'])
        ->assertStatus(503)->assertJsonPath('error_code', 'unavailable');
});

test('only known court portal pages can be opened, and the section is off unless configured', function (): void {
    [, $user] = courtPortalEmployee();
    Http::fake();

    $this->actingAs($user)->postJson(route('employee.court-cases.open'), ['target' => 'https://evil.test'])->assertStatus(422);
    $this->actingAs($user)->postJson(route('employee.court-cases.open'), ['target' => 'case:abc'])->assertStatus(422);
    Http::assertNothingSent();

    config(['court_ms.employee_portal' => false]);
    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'disabled')->where('features.court_portal', false));
    $this->actingAs($user)->postJson(route('employee.court-cases.open'), ['target' => 'dashboard'])->assertNotFound();
});

test('an account without an employee record has no court cases', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    Http::fake();

    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'no_employee'));
    Http::assertNothingSent();
});
