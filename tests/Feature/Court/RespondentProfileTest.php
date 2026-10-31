<?php

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Respondent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

function respondentProfileApplicant(): Applicant
{
    $applicant = Applicant::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911000100',
        'email' => 'respondent-profile@example.com',
        'address' => 'Addis Ababa',
        'national_id_number' => '1234567890123456',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);

    $applicant->markEmailAsVerified();

    Respondent::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'address' => 'Addis Ababa',
        'national_id' => '1234567890123456',
        'phone' => '0911000100',
        'email' => $applicant->email,
        'password' => Hash::make('password'),
    ]);

    return $applicant;
}

test('applicant and respondent logout both return to the plain applicant login', function () {
    $applicant = respondentProfileApplicant();

    $this->actingAs($applicant, 'applicant')
        ->post(route('court.applicant.logout'))
        ->assertRedirect(route('court.applicant.login'));

    $this->actingAs($applicant, 'applicant')
        ->post(route('court.respondent.logout'))
        ->assertRedirect(route('court.applicant.login'));
});

test('respondent profile exposes the same profile security and sessions navigation', function () {
    $applicant = respondentProfileApplicant();

    // The React page (Court/Respondent/Profile) posts to respondent.profile.update and .password and opens
    // respondent.profile.sessions.index for its Sessions tab; the portal shell is the respondent's.
    $this->actingAs($applicant, 'applicant')
        ->get(route('court.respondent.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/Profile')
            ->where('tab', 'profile')
            ->where('sessions', null)
            ->where('user.email', $applicant->email)
            ->where('user.national_id_number', '1234 5678 9012 3456')
            ->where('portal.name', 'respondent')
            ->where('portal.profile', 'court.respondent.profile.edit'));

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.respondent.profile.edit', ['tab' => 'security']))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Respondent/Profile')->where('tab', 'security'));
});

test('respondent profile updates linked profile records and password', function () {
    $applicant = respondentProfileApplicant();

    $this->actingAs($applicant, 'applicant')
        ->patch(route('court.respondent.profile.update'), [
            'first_name' => 'Abel',
            'middle_name' => 'K',
            'last_name' => 'Teka',
            'gender' => 'male',
            'position' => 'Senior Manager',
            'organization_name' => 'ACME',
            'address' => 'Adama',
            'national_id' => '1234567890123456',
            'phone' => '0911000100',
            'email' => $applicant->email,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(Respondent::where('email', $applicant->email)->value('address'))->toBe('Adama')
        ->and($applicant->fresh()->address)->toBe('Adama');

    $this->actingAs($applicant->fresh(), 'applicant')
        ->patch(route('court.respondent.profile.password'), [
            'current_password' => 'password',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])
        ->assertRedirect(route('court.respondent.profile.edit').'#security');

    expect(Hash::check('NewPassword1!', $applicant->fresh()->password))->toBeTrue()
        ->and(Hash::check('NewPassword1!', Respondent::where('email', $applicant->email)->firstOrFail()->password))->toBeTrue();
});

test('respondent can view and revoke another respondent session', function () {
    $applicant = respondentProfileApplicant();

    DB::table('sessions')->insert([
        'id' => 'respondent-other-session',
        'user_id' => $applicant->id,
        'ip_address' => '203.0.113.25',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/150.0.0.0',
        'payload' => base64_encode(serialize(['_auth_guard' => 'applicant'])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.respondent.profile.sessions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/Profile')
            ->where('tab', 'devices')
            ->where('sessions', fn ($sessions) => collect($sessions)->contains(fn (array $session): bool => $session['id'] === \Modules\Court\Application\Support\SessionRows::publicKey('respondent-other-session')
                && str_contains($session['device_name'], 'Windows')
                && str_contains($session['browser_name'], 'Chrome 150')
                && $session['is_current'] === false
                && $session['ip_address'] === '203.0.113.25')));

    $this->actingAs($applicant, 'applicant')
        ->delete(route('court.respondent.profile.sessions.destroy', \Modules\Court\Application\Support\SessionRows::publicKey('respondent-other-session')))
        ->assertRedirect();

    $this->assertDatabaseMissing('sessions', ['id' => 'respondent-other-session']);
});
