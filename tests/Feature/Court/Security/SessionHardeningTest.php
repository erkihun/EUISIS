<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Support\SessionRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
| The sessions table is shared by the staff and applicant guards, and user_id is
| only unique per guard. The session pages address rows by a keyed hash, never by
| the id the session cookie carries, and a staff account never sees or ends an
| applicant's session that happens to share its numeric id (#3, 04072fe, 1059d39).
*/

function sessionHardeningRow(string $id, int $userId, ?string $guard): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $userId, 'ip_address' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'payload' => base64_encode(serialize($guard ? ['_auth_guard' => $guard] : [])),
        'last_activity' => now()->getTimestamp(),
    ]);
}

beforeEach(function (): void {
    config(['session.driver' => 'database']);
});




test('the applicant sessions page sends keyed handles and ends a session only by its handle', function (): void {
    $applicant = Applicant::create([
        'first_name' => 'Sara', 'middle_name' => 'K', 'last_name' => 'Tesfaye', 'gender' => 'female',
        'position' => 'Officer', 'organization_name' => 'Org', 'phone' => '09'.random_int(10000000, 99999999),
        'email' => 'session-hardening-'.Str::lower(Str::random(6)).'@example.com', 'address' => 'Addis Ababa',
        'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'password' => Hash::make('password'), 'is_active' => true, 'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();
    sessionHardeningRow('applicant-other-device', $applicant->id, 'applicant');

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.profile.sessions.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sessions', fn ($sessions): bool => collect($sessions)->pluck('id')->contains(SessionRows::publicKey('applicant-other-device'))
            && ! collect($sessions)->pluck('id')->contains('applicant-other-device')));

    $this->delete(route('court.applicant.profile.sessions.destroy', 'applicant-other-device'))->assertRedirect();
    $this->assertDatabaseHas('sessions', ['id' => 'applicant-other-device']);

    $this->delete(route('court.applicant.profile.sessions.destroy', SessionRows::publicKey('applicant-other-device')))->assertRedirect();
    $this->assertDatabaseMissing('sessions', ['id' => 'applicant-other-device']);
});
