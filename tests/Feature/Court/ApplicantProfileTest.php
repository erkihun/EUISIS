<?php

use Modules\Court\Domain\Models\Applicant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

test('applicant profile page is displayed', function () {
    $applicant = Applicant::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911000000',
        'email' => 'abel@example.com',
        'address' => 'Addis Ababa',
        'national_id_number' => '1234567890123456',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    $this
        ->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Profile')
            ->where('profile.email', 'abel@example.com'));
});

test('applicant can update profile with an empty middle name', function () {
    $applicant = Applicant::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911000000',
        'email' => 'abel@example.com',
        'address' => 'Addis Ababa',
        'national_id_number' => '1234567890123456',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    $response = $this
        ->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.profile.edit'))
        ->patch(route('court.applicant.profile.update'), [
            'first_name' => 'Abel',
            'middle_name' => '',
            'last_name' => 'Teka',
            'gender' => 'male',
            'position' => 'Senior Manager',
            'organization_name' => 'ACME',
            'phone' => '0911000000',
            'email' => 'abel@example.com',
            'address' => 'Addis Ababa',
            'national_id_number' => '1234 5678 9012 3456',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.applicant.profile.edit'));

    $applicant->refresh();

    expect($applicant->middle_name)->toBe('')
        ->and($applicant->gender)->toBe('male')
        ->and($applicant->position)->toBe('Senior Manager')
        ->and($applicant->getRawOriginal('national_id_number'))->toBe('1234567890123456');
});

test('applicant profile update validates duplicate national id after normalization', function () {
    $applicant = Applicant::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911000000',
        'email' => 'abel@example.com',
        'address' => 'Addis Ababa',
        'national_id_number' => '1234567890123456',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    Applicant::create([
        'first_name' => 'Sara',
        'middle_name' => 'M',
        'last_name' => 'Bekele',
        'gender' => 'female',
        'position' => 'Counsel',
        'organization_name' => 'Beta PLC',
        'phone' => '0911000001',
        'email' => 'sara@example.com',
        'address' => 'Adama',
        'national_id_number' => '9999888877776666',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => true,
    ]);

    $response = $this
        ->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.profile.edit'))
        ->patch(route('court.applicant.profile.update'), [
            'first_name' => 'Abel',
            'middle_name' => 'K',
            'last_name' => 'Teka',
            'gender' => 'male',
            'position' => 'Manager',
            'organization_name' => 'ACME',
            'phone' => '0911000000',
            'email' => 'abel@example.com',
            'address' => 'Addis Ababa',
            'national_id_number' => '9999 8888 7777 6666',
        ]);

    $response
        ->assertSessionHasErrors('national_id_number')
        ->assertRedirect(route('court.applicant.profile.edit'));

expect($applicant->fresh()->getRawOriginal('national_id_number'))->toBe('1234567890123456');
});

test('applicant profile exposes profile security and sessions navigation', function () {
    $applicant = Applicant::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911000000',
        'email' => 'abel-navigation@example.com',
        'address' => 'Addis Ababa',
        'national_id_number' => '1234567890123456',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    // The Profile · Security · Sessions tabs are drawn by the React page (court/components/applicant/AccountTabs):
    // the profile opens on the account tab, and the sessions tab is the sessions page.
    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Profile')
            ->where('securitySuccess', null));

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.profile.sessions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Sessions'));
});

test('applicant can change password from profile security', function () {
    $applicant = Applicant::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911000000',
        'email' => 'abel-password@example.com',
        'address' => 'Addis Ababa',
        'national_id_number' => '1234567890123456',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    $this->actingAs($applicant, 'applicant')
        ->patch(route('court.applicant.profile.password.update'), [
            'current_password' => 'password',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])
        ->assertRedirect(route('court.applicant.profile.edit').'#security');

    expect(Hash::check('NewPassword1!', $applicant->refresh()->password))->toBeTrue();
});

test('applicant can view and revoke another session', function () {
    $applicant = Applicant::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911000000',
        'email' => 'abel-sessions@example.com',
        'address' => 'Addis Ababa',
        'national_id_number' => '1234567890123456',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    DB::table('sessions')->insert([
        'id' => 'applicant-other-session',
        'user_id' => $applicant->id,
        'ip_address' => '203.0.113.20',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/150.0.0.0',
        'payload' => base64_encode(serialize(['_auth_guard' => 'applicant'])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.profile.sessions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Sessions')
            ->where('sessions', fn ($sessions) => collect($sessions)->contains(fn ($session) => $session['id'] === \Modules\Court\Application\Support\SessionRows::publicKey('applicant-other-session')
                && $session['device_name'] === 'Windows'
                && $session['browser_name'] === 'Chrome 150')));

    $this->actingAs($applicant, 'applicant')
        ->delete(route('court.applicant.profile.sessions.destroy', \Modules\Court\Application\Support\SessionRows::publicKey('applicant-other-session')))
        ->assertRedirect();

    $this->assertDatabaseMissing('sessions', ['id' => 'applicant-other-session']);
});
