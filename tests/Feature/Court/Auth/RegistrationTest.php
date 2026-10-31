<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CourtStaffMember as User;

/*
| Staff accounts are created by administrators (Users > Create). There is no
| public sign-up for the staff portal: a self-registered account could open
| the admin dashboard and read case numbers and applicant names.
*/

test('there is no public staff registration page', function (): void {
    $this->get('/register')->assertNotFound();
});

test('posting to the old staff registration URL creates no account', function (): void {
    $this->post('/register', [
        'name' => 'Outsider',
        'email' => 'outsider@example.com',
        'national_id_number' => '1234567890123456',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ])->assertStatus(404);

    $this->assertGuest();
    expect(User::query()->where('email', 'outsider@example.com')->exists())->toBeFalse();
});

test('applicants can still register through their own portal', function (): void {
    $this->get(route('court.applicant.register'))->assertOk();
});
