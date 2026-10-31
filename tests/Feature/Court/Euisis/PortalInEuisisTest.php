<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\IntegrationClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
| Flow 6: with EUISIS_PORTAL_IN_EUISIS on, employees work on their cases in
| the EUISIS employee portal, so the applicant and respondent portal here,
| with its sign-in, registration and password pages, is gone from the web.
*/

function closePortal(?string $url = 'https://euisis.example.test/my-portal/court-cases'): void
{
    config(['court.euisis.portal_in_euisis' => true, 'court.euisis.portal_url' => $url]);
}

test('every applicant and respondent URL is not found, with no form behind it', function (): void {
    closePortal();

    foreach (['/court/applicant', '/court/applicant/login', '/court/applicant/register', '/court/applicant/forgot-password', '/court/applicant/cases', '/court/respondent', '/court/respondent/login', '/court/respondent/case-search', '/court/applicant/respondent/responses'] as $path) {
        // Not a sign-in redirect first: the members' pages are as gone as the sign-in.
        $this->get($path)->assertNotFound();
    }

    $this->post('/court/applicant/login', ['email' => 'someone@example.com', 'password' => 'secret'])->assertNotFound();
    $this->post('/court/applicant/register', [])->assertNotFound();
    $this->postJson('/court/applicant/cases/1/messages', ['body' => 'Hello'])->assertNotFound();
});

test('a court account still signed in is signed out, and the home page is the public site', function (): void {
    closePortal(null);
    $applicant = Applicant::create([
        'first_name' => 'Meron', 'middle_name' => 'A', 'last_name' => 'Haile', 'gender' => 'female', 'position' => 'Officer',
        'organization_name' => 'Org', 'phone' => '09'.random_int(10000000, 99999999), 'email' => 'moved-'.Str::lower(Str::random(6)).'@example.com',
        'address' => 'Addis Ababa', 'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'password' => Hash::make('password'), 'is_active' => true, 'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    $this->actingAs($applicant, 'applicant')->get('/court/applicant/dashboard')->assertNotFound();
    $this->assertGuest('applicant');

    $this->get('/court')->assertRedirect(route('court.landing.home'));
    $this->get(route('court.landing.home'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('courtPortal.closed', true));
});

test('EUISIS gets no sign-in link to the closed portal', function (): void {
    closePortal();
    $client = new IntegrationClient(['name' => 'EUISIS-'.Str::random(6), 'abilities' => IntegrationClient::DEFAULT_ABILITIES, 'allowed_ips' => []]);

    $this->withToken($client->issueToken())->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => ['uuid' => (string) Str::uuid()], 'target' => 'dashboard'])
        ->assertStatus(410)->assertJsonPath('error_code', 'portal_in_euisis');
});

test('with the setting off the portal works as before', function (): void {
    config(['court.euisis.portal_in_euisis' => false]);

    $this->get('/court/applicant/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Login'));
    $this->get('/court')->assertRedirect(route('court.applicant.login'));
});
