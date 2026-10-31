<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\AboutPage;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Notifications\ApplicantEmailOtp;
use Modules\Court\Application\Notifications\PasswordResetOtp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * The applicant portal's account side is React (Pages/Court/Applicant/*):
 * sign-in, registration, password reset, email verification, dashboard,
 * profile, sessions, notifications and their settings. These tests pin each
 * page's component and props, send the requests the pages send, and check the
 * portal shell's shared props on a signed-in page.
 */

function aapApplicant(array $attributes = []): Applicant
{
    $applicant = Applicant::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '+251911'.random_int(100000, 999999),
        'email' => 'aap-'.uniqid().'@example.com',
        'address' => 'Addis Ababa',
        'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
        ...$attributes,
    ]);
    $applicant->markEmailAsVerified();

    return $applicant;
}

function aapCase(Applicant $applicant, array $attributes = []): int
{
    $caseTypeId = DB::table('court_case_types')->insertGetId(['name' => 'Civil '.uniqid(), 'description' => null, 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('court_cases')->insertGetId([
        'applicant_id' => $applicant->id,
        'case_number' => 'AAP-'.strtoupper(substr(uniqid(), -6)),
        'title' => 'Account pages case',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

// ── Sign-in ──────────────────────────────────────────────────────────────────

test('the sign-in page is the React applicant login, uncached, with the About page beside it', function (): void {
    AboutPage::create(['title' => 'About the court', 'slug' => 'about', 'body' => '<p>Open <strong>daily</strong></p><script>alert(1)</script>', 'is_published' => true]);

    $response = $this->get(route('court.applicant.login'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Login')
            ->where('loginAs', 'applicant')
            ->where('email', '')
            ->where('bannerUrl', null)
            ->where('about.title', 'About the court')
            ->where('about.body', fn ($body) => str_contains((string) $body, '<strong>daily</strong>') && ! str_contains((string) $body, '<script>'))
            ->where('auth.user', null)
            ->where('portal.name', 'applicant')
            ->where('workspace', null));
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

test('the sign-in page opens on the respondent tab when asked', function (): void {
    $this->get(route('court.applicant.login', ['login_as' => 'respondent']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Login')->where('loginAs', 'respondent')->where('about', null));
});

test('signing in from the page payload lands an applicant on the dashboard', function (): void {
    $applicant = aapApplicant();

    $this->post(route('court.applicant.login.submit'), ['email' => $applicant->email, 'password' => 'password', 'remember' => false, 'login_as' => 'applicant'])
        ->assertRedirect(route('court.applicant.dashboard'));

    $this->assertAuthenticatedAs($applicant, 'applicant');
    expect(session('acting_as_respondent'))->toBeNull();
});

test('signing in on the respondent tab lands on the respondent dashboard', function (): void {
    $applicant = aapApplicant();

    $this->post(route('court.applicant.login.submit'), ['email' => $applicant->email, 'password' => 'password', 'remember' => false, 'login_as' => 'respondent'])
        ->assertRedirect(route('court.respondent.dashboard'));

    $this->assertAuthenticatedAs($applicant, 'applicant');
    expect(session('acting_as_respondent'))->toBeTrue();
});

test('a refused sign-in comes back to the page with the error, the email and the tab', function (): void {
    $applicant = aapApplicant();

    $this->from(route('court.applicant.login'))
        ->post(route('court.applicant.login.submit'), ['email' => $applicant->email, 'password' => 'wrong-password', 'remember' => false, 'login_as' => 'respondent'])
        ->assertRedirect(route('court.applicant.login'))
        ->assertSessionHasErrors('email');

    $this->get(route('court.applicant.login'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Login')
            ->where('loginAs', 'respondent')
            ->where('email', $applicant->email)
            ->has('errors.email'));
    $this->assertGuest('applicant');
});

// ── Registration ─────────────────────────────────────────────────────────────

test('the registration page is the React form, uncached', function (): void {
    $response = $this->get(route('court.applicant.register'));

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Register'));
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

test('registering from the page payload creates the account and signs it in', function (): void {
    $this->post(route('court.applicant.register.submit'), [
        'is_lawyer' => '0',
        'lawyer_document' => null,
        'first_name' => 'Hana',
        'middle_name' => 'T',
        'last_name' => 'Girma',
        'gender' => 'female',
        // The page sends the typed local number behind +251, and the National ID as grouped while typing.
        'phone' => '+251912345678',
        'national_id_number' => '1111 2222 3333 4444',
        'position' => 'Officer',
        'organization_name' => 'Beta PLC',
        'email' => 'hana.register@example.com',
        'address' => 'Adama',
        'password' => 'StrongPass1!',
        'password_confirmation' => 'StrongPass1!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.applicant.dashboard'));

    $applicant = Applicant::where('email', 'hana.register@example.com')->firstOrFail();
    expect($applicant->getRawOriginal('national_id_number'))->toBe('1111222233334444')
        ->and($applicant->phone)->toBe('+251912345678')
        ->and($applicant->is_lawyer)->toBeFalse();
    $this->assertAuthenticatedAs($applicant, 'applicant');
});

test('a lawyer registers with the credential PDF as multipart', function (): void {
    Storage::fake('court_private');

    $this->post(route('court.applicant.register.submit'), [
        'is_lawyer' => '1',
        'lawyer_document' => UploadedFile::fake()->create('licence.pdf', 120, 'application/pdf'),
        'first_name' => 'Dawit',
        'middle_name' => 'A',
        'last_name' => 'Bekele',
        'gender' => 'male',
        'phone' => '+251913000111',
        'national_id_number' => '5555 6666 7777 8888',
        'position' => 'Advocate',
        'organization_name' => 'Bekele Law',
        'email' => 'dawit.lawyer@example.com',
        'address' => 'Addis Ababa',
        'password' => 'StrongPass1!',
        'password_confirmation' => 'StrongPass1!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.applicant.dashboard'));

    $applicant = Applicant::where('email', 'dawit.lawyer@example.com')->firstOrFail();
    expect($applicant->is_lawyer)->toBeTrue()->and($applicant->lawyer_document_path)->not->toBeNull();
    Storage::disk('court_private')->assertExists($applicant->lawyer_document_path);
});

// ── Password reset (OTP) ─────────────────────────────────────────────────────

test('the password reset runs through its three React pages', function (): void {
    Notification::fake();
    $applicant = aapApplicant();

    $this->get(route('court.applicant.password.request'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/ForgotPassword')->where('status', null)->where('email', ''));

    // Without a code on the way, the later steps send the person back to the start.
    $this->get(route('court.applicant.password.otp.show'))->assertRedirect(route('court.applicant.password.request'));
    $this->get(route('court.applicant.password.new.show'))->assertRedirect(route('court.applicant.password.request'));

    $this->post(route('court.applicant.password.email'), ['email' => $applicant->email])
        ->assertRedirect(route('court.applicant.password.otp.show'));

    $code = null;
    Notification::assertSentTo($applicant, PasswordResetOtp::class, function (PasswordResetOtp $notification) use (&$code): bool {
        $code = (fn () => $this->code)->call($notification);

        return true;
    });

    $this->get(route('court.applicant.password.otp.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/PasswordOtp')->where('flash.info', __('court::auth.reset_code_sent')));

    $this->post(route('court.applicant.password.otp.verify'), ['code' => $code])
        ->assertRedirect(route('court.applicant.password.new.show'));

    $this->get(route('court.applicant.password.new.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/NewPassword'));

    $this->post(route('court.applicant.password.new.update'), ['password' => 'BrandNew1!', 'password_confirmation' => 'BrandNew1!'])
        ->assertRedirect(route('court.applicant.dashboard'));

    expect(Hash::check('BrandNew1!', $applicant->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($applicant, 'applicant');
});

test('a wrong reset code is reported on the code page', function (): void {
    $applicant = aapApplicant();

    $this->withSession([
        'applicant_pwd_otp_email' => $applicant->email,
        'applicant_pwd_otp_code' => hash('sha256', '123456'),
        'applicant_pwd_otp_expires' => now()->addMinutes(10)->timestamp,
    ])->from(route('court.applicant.password.otp.show'))
        ->post(route('court.applicant.password.otp.verify'), ['code' => '654321'])
        ->assertRedirect(route('court.applicant.password.otp.show'))
        ->assertSessionHasErrors(['code' => __('court::auth.password_reset_code_invalid')]);
});

// ── Email verification (OTP) ─────────────────────────────────────────────────

test('an unverified applicant gets the React code page and verifies with the code', function (): void {
    Notification::fake();
    $applicant = aapApplicant();
    $applicant->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.verification.notice'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/VerifyEmail')
            ->where('auth.user.email', $applicant->email)
            ->where('portal.name', 'applicant'));

    $code = null;
    Notification::assertSentTo($applicant, ApplicantEmailOtp::class, function (ApplicantEmailOtp $notification) use (&$code): bool {
        $code = (fn () => $this->code)->call($notification);

        return true;
    });

    $this->actingAs($applicant, 'applicant')
        ->post(route('court.applicant.verification.verify-otp'), ['code' => $code])
        ->assertRedirect(route('court.applicant.dashboard'));

    expect($applicant->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a verified applicant is sent past the code page', function (): void {
    $this->actingAs(aapApplicant(), 'applicant')
        ->get(route('court.applicant.verification.notice'))
        ->assertRedirect(route('court.applicant.dashboard'));
});

// ── Dashboard and the portal shell ───────────────────────────────────────────

test('the dashboard is the React portal home with the counts, recent cases and the portal shell', function (): void {
    $applicant = aapApplicant(['is_lawyer' => true]);
    aapCase($applicant, ['status' => 'pending']);
    aapCase($applicant, ['status' => 'active', 'title' => 'Second case']);

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Dashboard')
            ->where('applicant.name', $applicant->full_name)
            ->where('applicant.is_lawyer', true)
            ->where('counts.total', 2)
            ->where('counts.pending', 1)
            ->where('counts.active', 1)
            ->where('counts.closed', 0)
            ->where('counts.letters', 0)
            ->has('recent', 2)
            ->where('recent.0.created_at', fn ($at) => is_string($at) && str_contains($at, 'T'))
            ->has('recent.0.created_relative')
            ->has('letters', 0)
            ->has('responses', 0)
            ->has('responseReplies', 0)
            ->has('decisions', 0)
            ->where('today', fn ($today) => is_string($today) && str_contains($today, 'T'))
            // The portal shell (HandleInertiaRequests + PortalNavigation).
            ->where('auth.user.id', $applicant->id)
            ->where('auth.user.email', $applicant->email)
            ->where('auth.user.is_lawyer', true)
            ->where('portal.name', 'applicant')
            ->where('portal.profile', 'court.applicant.profile.edit')
            ->where('portal.logout', 'court.applicant.logout')
            ->where('workspace.home', 'court.applicant.dashboard')
            ->has('workspace.nav.0.groups.0.items'));
});

// ── Profile ──────────────────────────────────────────────────────────────────

test('the profile is the React page with the account fields', function (): void {
    $applicant = aapApplicant(['national_id_number' => '1234567890123456']);

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Profile')
            ->where('profile.first_name', 'Abel')
            ->where('profile.email', $applicant->email)
            ->where('profile.national_id_number', '1234 5678 9012 3456')
            ->where('profile.is_lawyer', false)
            ->where('profile.has_lawyer_document', false)
            ->where('securitySuccess', null)
            ->where('portal.name', 'applicant'));
});

test('saving the profile from the page payload updates the account', function (): void {
    $applicant = aapApplicant();

    $this->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.profile.edit'))
        ->patch(route('court.applicant.profile.update'), [
            'first_name' => 'Abel',
            'middle_name' => '',
            'last_name' => 'Teka',
            'gender' => 'male',
            'phone' => $applicant->phone,
            'national_id_number' => '9876 5432 1098 7654',
            'email' => $applicant->email,
            'address' => 'Bahir Dar',
            'position' => 'Director',
            'organization_name' => 'ACME',
            'lawyer_document' => null,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.applicant.profile.edit'))
        ->assertSessionHas('success');

    $applicant->refresh();
    expect($applicant->address)->toBe('Bahir Dar')
        ->and($applicant->position)->toBe('Director')
        ->and($applicant->middle_name)->toBe('')
        ->and($applicant->getRawOriginal('national_id_number'))->toBe('9876543210987654');
});

test('a lawyer replaces the credential document with the spoofed multipart PATCH the page sends', function (): void {
    Storage::fake('court_private');
    $applicant = aapApplicant(['is_lawyer' => true]);

    $this->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.profile.edit'))
        ->post(route('court.applicant.profile.update'), [
            '_method' => 'patch',
            'first_name' => 'Abel',
            'middle_name' => 'K',
            'last_name' => 'Teka',
            'gender' => 'male',
            'phone' => $applicant->phone,
            'national_id_number' => $applicant->national_id_number,
            'email' => $applicant->email,
            'address' => 'Addis Ababa',
            'position' => 'Manager',
            'organization_name' => 'ACME',
            'lawyer_document' => UploadedFile::fake()->create('licence.pdf', 80, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.applicant.profile.edit'));

    $path = $applicant->fresh()->lawyer_document_path;
    expect($path)->not->toBeNull();
    Storage::disk('court_private')->assertExists($path);

    $this->actingAs($applicant->fresh(), 'applicant')
        ->get(route('court.applicant.profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('profile.is_lawyer', true)->where('profile.has_lawyer_document', true));
});

test('changing the password from the security tab comes back to it with the notice', function (): void {
    $applicant = aapApplicant();

    $this->actingAs($applicant, 'applicant')
        ->patch(route('court.applicant.profile.password.update'), ['current_password' => 'password', 'password' => 'NewPassword1!', 'password_confirmation' => 'NewPassword1!'])
        ->assertRedirect(route('court.applicant.profile.edit').'#security');

    expect(Hash::check('NewPassword1!', $applicant->fresh()->password))->toBeTrue();

    $this->actingAs($applicant->fresh(), 'applicant')
        ->get(route('court.applicant.profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Profile')->where('securitySuccess', __('court::auth.profile.saved')));
});

test('a wrong current password is refused on the security tab', function (): void {
    $applicant = aapApplicant();

    $this->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.profile.edit'))
        ->patch(route('court.applicant.profile.password.update'), ['current_password' => 'not-it', 'password' => 'NewPassword1!', 'password_confirmation' => 'NewPassword1!'])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('password', $applicant->fresh()->password))->toBeTrue();
});

// ── Sessions ─────────────────────────────────────────────────────────────────

test('the sessions page lists the applicant sessions and ends another one from the page', function (): void {
    $applicant = aapApplicant();
    DB::table('sessions')->insert([
        'id' => 'aap-other-session',
        'user_id' => $applicant->id,
        'ip_address' => '203.0.113.30',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/150.0.0.0',
        'payload' => base64_encode(serialize(['_auth_guard' => 'applicant'])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.profile.sessions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Sessions')
            ->where('supported', config('session.driver') === 'database')
            ->where('status', null)
            ->where('sessions', fn ($sessions) => collect($sessions)->contains(fn ($session) => $session['id'] === \Modules\Court\Application\Support\SessionRows::publicKey('aap-other-session')
                && $session['device_name'] === 'Windows'
                && $session['browser_name'] === 'Chrome 150'
                && $session['ip_address'] === '203.0.113.30'
                && $session['is_current'] === false
                && is_string($session['last_active_relative'])))
            ->where('sessions', fn ($sessions) => collect($sessions)->contains(fn ($session) => $session['is_current'] === true)));

    $this->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.profile.sessions.index'))
        ->delete(route('court.applicant.profile.sessions.destroy', \Modules\Court\Application\Support\SessionRows::publicKey('aap-other-session')))
        ->assertRedirect(route('court.applicant.profile.sessions.index'));

    $this->assertDatabaseMissing('sessions', ['id' => 'aap-other-session']);

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.profile.sessions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('status', __('court::auth.session_revoked')));
});

// ── Notifications ────────────────────────────────────────────────────────────

test('the notifications page pages its four lists and marks items seen from the page', function (): void {
    $applicant = aapApplicant();
    $caseId = aapCase($applicant);
    $staff = User::factory()->create();

    $hearingId = DB::table('court_case_hearings')->insertGetId(['case_id' => $caseId, 'hearing_at' => now()->addDays(3), 'type' => 'Preliminary', 'location' => 'Courtroom 2', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('court_case_messages')->insert([
        ['case_id' => $caseId, 'sender_user_id' => $staff->id, 'body' => 'Please bring the original documents.', 'created_at' => now(), 'updated_at' => now()],
        ['case_id' => $caseId, 'sender_user_id' => $staff->id, 'body' => 'https://court.example/case-letters/5', 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()],
    ]);
    DB::table('court_case_status_logs')->insert(['case_id' => $caseId, 'from_status' => 'pending', 'to_status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Notifications')
            ->where('hearings.total', 1)
            ->where('hearings.data.0.id', $hearingId)
            ->where('hearings.data.0.type', 'Preliminary')
            ->where('hearings.data.0.location', 'Courtroom 2')
            ->where('hearings.data.0.at', fn ($at) => is_string($at) && str_contains($at, 'T'))
            ->where('messages.total', 2)
            ->where('messages.data.0.excerpt', 'Please bring the original documents.')
            ->where('messages.data.0.url', null)
            ->where('messages.data.1.url', 'https://court.example/case-letters/5')
            ->where('statusChanges.total', 1)
            ->where('statusChanges.data.0.from', __('court::app.status.pending'))
            ->where('statusChanges.data.0.to', __('court::app.status.active'))
            ->where('respondentViews.total', 0)
            ->where('portal.name', 'applicant'));

    $this->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.notifications.index'))
        ->post(route('court.applicant.notifications.markOne'), ['type' => 'hearing', 'sourceId' => $hearingId])
        ->assertRedirect(route('court.applicant.notifications.index'));

    $this->assertDatabaseHas('court_notification_reads', ['applicant_id' => $applicant->id, 'type' => 'hearing', 'source_id' => $hearingId]);

    $this->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.notifications.index'))
        ->post(route('court.applicant.notifications.markAll'), [])
        ->assertRedirect(route('court.applicant.notifications.index'));

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.notifications.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hearings.total', 0)
            ->where('messages.total', 0)
            ->where('statusChanges.total', 0));
});

test('the notification settings page shows the defaults and saves the page payload', function (): void {
    $applicant = aapApplicant();

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.notifications.settings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/NotificationSettings')
            ->where('prefs', ['email_status' => true, 'email_hearing' => true, 'email_message' => true, 'email_weekly_digest' => false]));

    $this->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.notifications.settings'))
        ->post(route('court.applicant.notifications.settings.update'), ['email_status' => false, 'email_hearing' => true, 'email_message' => false, 'email_weekly_digest' => true])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.applicant.notifications.settings'));

    $this->assertDatabaseHas('court_applicant_notification_prefs', ['applicant_id' => $applicant->id, 'email_status' => 0, 'email_hearing' => 1, 'email_message' => 0, 'email_weekly_digest' => 1]);

    $this->actingAs($applicant, 'applicant')
        ->get(route('court.applicant.notifications.settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('prefs', ['email_status' => false, 'email_hearing' => true, 'email_message' => false, 'email_weekly_digest' => true]));
});
