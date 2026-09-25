<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\CafeteriaProvider;
use App\Models\Employee;
use App\Models\EmployeeRegistrationOtp;
use App\Models\PasswordHistory;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\ProviderUser;
use App\Models\ServiceProviderUser;
use App\Models\ServiceType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\PasswordSecurityNotification;
use App\Security\Passwords\BreachCheckResult;
use App\Security\Passwords\CompromisedPasswordChecker;
use App\Security\Passwords\HibpCompromisedPasswordChecker;
use App\Security\Passwords\PasswordLifecycle;
use App\Security\Passwords\PasswordPolicy;
use App\Security\Passwords\Rules\PasswordIsNotCommon;
use App\Services\ErrorLoggingService;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Password policy — end to end (docs/password-security-policy.md)
|--------------------------------------------------------------------------
| Numbered after the requirement list. The breach service is replaced by a
| fake: no test touches the network.
*/

const PP_GOOD = 'violet harbor lanterns drift slowly';

beforeEach(function (): void {
    app()->setLocale('en');
    config(['security.mfa_enforce' => false, 'security.passwords.breach_check.enabled' => true]);
    PasswordIsNotCommon::flush();
    ppBreachFake();
});

/** Breach corpus fake: listed passwords are "compromised"; `unavailable` simulates an outage. */
function ppBreachFake(array $compromised = ['Tr0ub4dor&3 is still breached'], bool $unavailable = false): void
{
    app()->instance(CompromisedPasswordChecker::class, new class($compromised, $unavailable) implements CompromisedPasswordChecker
    {
        public function __construct(private array $compromised, private bool $unavailable) {}

        public function check(#[SensitiveParameter] string $password): BreachCheckResult
        {
            if ($this->unavailable) {
                return BreachCheckResult::Unavailable;
            }

            return in_array($password, $this->compromised, true) ? BreachCheckResult::Compromised : BreachCheckResult::Clean;
        }
    });
}

/** Abebe Kebede, AAC-48392017, 0911234567 — an employee with a linked account. */
function ppEmployeeUser(array $attributes = []): User
{
    Employee::query()->create([
        'employee_number' => 'AAC-48392017', 'first_name' => 'Abebe', 'middle_name' => 'Tesfaye', 'last_name' => 'Kebede',
        'full_name' => 'አበበ ተስፋዬ ከበደ', 'name_en' => 'Abebe Tesfaye Kebede', 'status' => 'active',
        'email' => 'abebe.kebede@example.gov.et', 'phone' => '0911234567',
    ]);

    return User::factory()->create(array_merge([
        'name' => 'Abebe Kebede', 'email' => 'abebe.kebede@example.gov.et', 'status' => 'active',
        'password' => 'Initial passphrase river 11', 'must_change_password' => false,
    ], $attributes));
}

/** @return string|null the first password error, or null when the policy accepts it */
function ppError(string $password, ?Model $account = null, array $identity = [], ?string $confirmation = null): ?string
{
    $validator = Validator::make(
        ['password' => $password, 'password_confirmation' => $confirmation ?? $password],
        ['password' => app(PasswordPolicy::class)->rules($account, $identity)],
    );

    return $validator->fails() ? $validator->errors()->first('password') : null;
}

function ppProviderUser(array $attributes = []): ProviderUser
{
    ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria Service', 'is_active' => true]);
    $type = ProviderType::query()->firstOrCreate(['code' => 'CAFETERIA'], ['name_en' => 'Cafeteria', 'is_active' => true]);
    $provider = Provider::query()->create(['provider_code' => 'PP-CAF', 'provider_type_id' => $type->id, 'name_en' => 'Policy Cafeteria', 'status' => 'active']);
    DB::table('provider_services')->insert([
        'id' => (string) Str::uuid7(), 'provider_id' => $provider->id,
        'service_type_id' => ServiceType::query()->where('code', 'cafeteria')->value('id'),
        'status' => 'active', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    CafeteriaProvider::query()->create(['provider_id' => $provider->id, 'code' => 'PP-CAF', 'name_en' => 'Policy Cafeteria', 'is_active' => true]);

    return ProviderUser::query()->create(array_merge([
        'provider_id' => $provider->id, 'name' => 'Selamawit Operator', 'email' => 'selamawit.ops@example.test',
        'username' => 'selamawit.ops', 'password' => Hash::make('Provider start phrase 77'),
        'provider_role' => 'operator', 'status' => 'active', 'portal_enabled' => true,
    ], $attributes));
}

function ppSuperAdmin(): User
{
    Role::findOrCreate('Super Admin', 'web');
    $admin = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
    $admin->assignRole('Super Admin');

    return $admin;
}

// ── Personal information (1–12) ─────────────────────────────────────────────

test('1-5 a password containing the first, middle or last name, the full name or a case variant is rejected', function (string $password): void {
    expect(ppError($password, ppEmployeeUser()))->toBe(__('password-policy.contains_name'));
})->with([
    'first name' => 'Abebe2026SecureHorizonLine',
    'middle name' => 'Tesfaye riverbank lantern 9',
    'last name' => 'quiet morning Kebede walk',
    'full name joined' => 'AbebeKebede harbor walk 2026',
    'full name spaced' => 'my abebe kebede passphrase',
    'case variant' => 'ABEBEkebede-harbor-walk!',
]);

test('6 a password containing the username is rejected', function (): void {
    expect(ppError('abebe.kebede-LongPassphrase', null, ['username' => 'abebe.kebede', 'name' => 'X Y']))
        ->toBeIn([__('password-policy.contains_username'), __('password-policy.contains_name')]);
    expect(ppError('silver owl hkworker77 meadow', null, ['username' => 'hkworker77']))->toBe(__('password-policy.contains_username'));
});

test('7 a password containing the email local part is rejected', function (): void {
    expect(ppError('Sunny hk.worker77 fields bloom', null, ['email' => 'hk.worker77@example.com']))
        ->toBe(__('password-policy.contains_email'));
});

test('8-9 a password containing the full employee number, in any punctuation, is rejected', function (string $password): void {
    expect(ppError($password, ppEmployeeUser()))->toBe(__('password-policy.contains_employee_number'));
})->with(['MyAAC-48392017Secret phrase', 'aac48392017 orange lantern', 'AAC 48392017 violet sky']);

test('10 a password containing the phone number in any Ethiopian format is rejected', function (string $password): void {
    expect(ppError($password, ppEmployeeUser()))->toBe(__('password-policy.contains_phone'));
})->with(['0911234567 blue fields ahead', '+251911234567 harbor lights', 'call 251-911-234-567 anytime']);

test('11 unrelated fragments do not cause false positives', function (): void {
    $user = ppEmployeeUser();

    expect(ppError('Salient harbor lanterns 2026', null, ['name' => 'Ali Wu']))->toBeNull()  // "ali" is too short to count
        ->and(ppError('4839 lanterns shine brightly', $user))->toBeNull()                    // part of the employee number
        ->and(ppError('example gardens bloom brightly', $user))->toBeNull()                  // email domain is not personal
        ->and(ppError('mail sorted at the com desk today', $user))->toBeNull();               // generic substrings
});

test('12 Amharic names are checked in Ethiopic script', function (): void {
    $user = ppEmployeeUser();

    expect(ppError('የአበበ ምስጢር ቃል ሁልጊዜ 2026', $user))->toBe(__('password-policy.contains_name'))
        ->and(ppError('ሰማያዊ ወንዝ በጠዋት ይፈሳል 2026', $user))->toBeNull();
});

// ── History (13–23) ─────────────────────────────────────────────────────────

/** Change the password $count times through the lifecycle; returns every password used, oldest first. */
function ppChangeTimes(User $user, int $count): array
{
    $passwords = ['Initial passphrase river 11'];
    for ($i = 1; $i <= $count; $i++) {
        $next = "Rotating passphrase number {$i} maple";
        app(PasswordLifecycle::class)->change($user, $next, AuditEventType::UserPasswordChanged);
        $passwords[] = $next;
    }

    return $passwords;
}

test('13 the new password cannot equal the current one', function (): void {
    $user = ppEmployeeUser();

    $this->actingAs($user)->from('/profile')->put(route('password.update'), [
        'current_password' => 'Initial passphrase river 11',
        'password' => 'Initial passphrase river 11',
        'password_confirmation' => 'Initial passphrase river 11',
    ])->assertSessionHasErrors(['password' => __('password-policy.reused', ['count' => 5])]);
});

test('14-18 each of the previous 5 passwords is rejected', function (int $back): void {
    $user = ppEmployeeUser();
    $passwords = ppChangeTimes($user, 6);  // current = #6; history = #5..#1
    $candidate = $passwords[6 - $back];

    expect(ppError($candidate, $user->fresh()))->toBe('You cannot reuse your current password or any of your last 5 passwords.');
})->with([1, 2, 3, 4, 5]);

test('19 a password older than the history window may be used again', function (): void {
    $user = ppEmployeeUser();
    $passwords = ppChangeTimes($user, 6);

    expect(ppError($passwords[0], $user->fresh()))->toBeNull()
        ->and(PasswordHistory::query()->where('authenticatable_id', (string) $user->id)->count())->toBe(5);
});

test('20 history hashes and password hashes are never serialized', function (): void {
    $user = ppEmployeeUser();
    ppChangeTimes($user, 2);

    $history = PasswordHistory::query()->first();
    expect($history->toArray())->not->toHaveKey('password_hash')
        ->and($user->fresh()->toArray())->not->toHaveKey('password')
        ->and(json_encode($history))->not->toContain('$argon2id$');
});

test('21 history is written only when a change succeeds', function (): void {
    $user = ppEmployeeUser();

    $this->actingAs($user)->from('/profile')->put(route('password.update'), [
        'current_password' => 'wrong current password here',
        'password' => PP_GOOD,
        'password_confirmation' => PP_GOOD,
    ])->assertSessionHasErrors('current_password');

    expect(PasswordHistory::query()->count())->toBe(0);

    $this->actingAs($user)->from('/profile')->put(route('password.update'), [
        'current_password' => 'Initial passphrase river 11',
        'password' => PP_GOOD,
        'password_confirmation' => PP_GOOD,
    ])->assertSessionHasNoErrors();

    expect(PasswordHistory::query()->count())->toBe(1)
        ->and(Hash::check('Initial passphrase river 11', PasswordHistory::query()->value('password_hash')))->toBeTrue();
});

test('22 a refused change leaves password and history untouched', function (): void {
    $user = ppEmployeeUser();
    $before = $user->fresh()->password;

    expect(fn () => app(PasswordLifecycle::class)->change($user, 'Initial passphrase river 11', AuditEventType::UserPasswordChanged))
        ->toThrow(ValidationException::class);

    expect($user->fresh()->password)->toBe($before)
        ->and(PasswordHistory::query()->count())->toBe(0);
});

test('23 two racing changes to the same new password end consistent: one wins, the other is refused', function (): void {
    $user = ppEmployeeUser();
    $stale = User::query()->find($user->id); // a second request's copy, loaded before either change

    app(PasswordLifecycle::class)->change($user, PP_GOOD, AuditEventType::UserPasswordChanged);

    // The losing request re-checks under the row lock and sees the winner's password as current.
    expect(fn () => app(PasswordLifecycle::class)->change($stale, PP_GOOD, AuditEventType::UserPasswordChanged))
        ->toThrow(ValidationException::class);

    expect(Hash::check(PP_GOOD, $user->fresh()->password))->toBeTrue()
        ->and(PasswordHistory::query()->count())->toBe(1);
});

// ── Strength (24–32) ────────────────────────────────────────────────────────

test('24 a password shorter than 15 characters is rejected', function (): void {
    expect(ppError('Short pass 12'))->not->toBeNull()
        ->and(app(PasswordPolicy::class)->minLength())->toBe(15);
});

test('25-27 long passphrases, spaces and Unicode are accepted; no composition rules', function (string $password): void {
    expect(ppError($password))->toBeNull();
})->with([
    'lowercase passphrase with spaces' => PP_GOOD,
    'Amharic passphrase' => 'ሰማያዊ ወንዝ በጠዋት ይፈሳል ዛሬ',
    'mixed scripts and symbols' => 'ቡና & coffee at dawn — ☕ 17',
]);

test('28 passwords are verified in full: nothing after byte 72 is ignored, and over 128 characters is refused', function (): void {
    $prefix = str_repeat('ሀ', 30); // 90 bytes of UTF-8
    $hash = Hash::make($prefix.' ending one');

    expect(Hash::check($prefix.' ending one', $hash))->toBeTrue()
        ->and(Hash::check($prefix.' ending two', $hash))->toBeFalse()   // bcrypt would say true here
        ->and($hash)->toStartWith('$argon2id$')
        ->and(ppError(str_repeat('lantern ', 17)))->not->toBeNull();    // 136 characters
});

test('29 common, predictable and service-specific passwords are rejected', function (string $password): void {
    expect(ppError($password))->toBe(__('password-policy.common'));
})->with([
    'Password123456789!', 'EUISIS-Admin-2026!!', 'AddisAbaba12345!!', 'Welcome1234567890', 'Employee123456789',
    '123456789012345', 'qwertyuiopasdfgh', 'aaaaaaaaaaaaaaaa', 'abcabcabcabcabcabc', 'iloveyou2026!!!!!',
]);

test('30 a breached password is rejected; the check fails closed only for privileged accounts', function (): void {
    expect(ppError('Tr0ub4dor&3 is still breached'))->toBe(__('password-policy.compromised'));
    expect(AuditLog::query()->where('event_type', AuditEventType::PasswordCompromisedRejected->value)->exists())->toBeTrue();

    ppBreachFake(unavailable: true);
    $admin = ppSuperAdmin();

    expect(ppError(PP_GOOD, $admin))->toBe(__('password-policy.breach_check_unavailable'))
        ->and(ppError(PP_GOOD, ppEmployeeUser()))->toBeNull();
});

test('30 the breach check sends only a 5-character hash prefix, never the password or its full hash', function (): void {
    $password = 'correct horse battery staple 42';
    $digest = strtoupper(sha1($password));
    Http::fake(['*' => Http::response(substr($digest, 5).":3\r\nABCDEF0123456789ABCDEF0123456789ABC:0")]);

    expect((new HibpCompromisedPasswordChecker)->check($password))->toBe(BreachCheckResult::Compromised);

    Http::assertSent(function ($request) use ($password, $digest): bool {
        return str_ends_with($request->url(), '/'.substr($digest, 0, 5))
            && ! str_contains($request->url().json_encode($request->headers()).$request->body(), $password)
            && ! str_contains($request->url(), substr($digest, 5))
            && $request->hasHeader('Add-Padding');
    });
});

test('31 password managers work: no paste blocking and no truncating maxlength on password fields', function (): void {
    $files = ['Pages/Auth/Login.tsx', 'Pages/Auth/ResetPassword.tsx', 'Pages/Auth/Register.tsx', 'Pages/Auth/ForcedPasswordChange.tsx',
        'Pages/Profile/Partials/UpdatePasswordForm.tsx', 'Pages/Users/Create.tsx', 'Pages/Users/Edit.tsx', 'Pages/ProviderPortal/Profile.tsx'];

    foreach ($files as $file) {
        $source = file_get_contents(resource_path('js/'.$file));
        expect($source)->not->toContain('onPaste')->not->toContain('minLength={8}');
        expect(preg_match('/type="password"[^>]*maxLength/s', $source))->toBe(0);
    }
});

test('32 a confirmation mismatch is rejected', function (): void {
    expect(ppError(PP_GOOD, null, [], 'something else entirely'))->not->toBeNull();
});

// ── Flows (33–41) ───────────────────────────────────────────────────────────

test('33 the profile change applies the policy and keeps this session signed in', function (): void {
    $user = ppEmployeeUser();

    $this->actingAs($user)->from('/profile')->put(route('password.update'), [
        'current_password' => 'Initial passphrase river 11', 'password' => 'Kebede harbor walk 2026', 'password_confirmation' => 'Kebede harbor walk 2026',
    ])->assertSessionHasErrors(['password' => __('password-policy.contains_name')]);

    $this->actingAs($user)->from('/profile')->put(route('password.update'), [
        'current_password' => 'Initial passphrase river 11', 'password' => PP_GOOD, 'password_confirmation' => PP_GOOD,
    ])->assertSessionHasNoErrors();

    // Still signed in on this device (other devices are signed out by AuthenticateSession).
    $this->get(route('employee.portal'))->assertOk();
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->password_changed_at)->not->toBeNull();
});

test('34 forgot/reset applies the policy, including history, only after the token is verified', function (): void {
    Notification::fake();
    $user = ppEmployeeUser();
    $this->post(route('password.email'), ['email' => $user->email]);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    // Without a valid token, the current password is NOT reported as "reused" (no oracle).
    $this->post(route('password.store'), [
        'token' => 'not-the-token', 'email' => $user->email,
        'password' => 'Initial passphrase river 11', 'password_confirmation' => 'Initial passphrase river 11',
    ])->assertSessionHasErrors('email')->assertSessionDoesntHaveErrors('password');

    // With the token: personal data and the current password are refused, the token survives.
    $this->post(route('password.store'), [
        'token' => $token, 'email' => $user->email, 'password' => 'Abebe harbor lights 2026', 'password_confirmation' => 'Abebe harbor lights 2026',
    ])->assertSessionHasErrors(['password' => __('password-policy.contains_name')]);
    $this->post(route('password.store'), [
        'token' => $token, 'email' => $user->email, 'password' => 'Initial passphrase river 11', 'password_confirmation' => 'Initial passphrase river 11',
    ])->assertSessionHasErrors('password');

    $this->post(route('password.store'), [
        'token' => $token, 'email' => $user->email, 'password' => PP_GOOD, 'password_confirmation' => PP_GOOD,
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check(PP_GOOD, $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('event_type', AuditEventType::PasswordResetCompleted->value)->exists())->toBeTrue();

    // 49: single use (past the per-IP reset throttle, so the token itself is what refuses it).
    $this->travel(2)->minutes();
    $this->post(route('password.store'), [
        'token' => $token, 'email' => $user->email, 'password' => 'another fine meadow phrase', 'password_confirmation' => 'another fine meadow phrase',
    ])->assertSessionHasErrors('email');
    expect(Hash::check(PP_GOOD, $user->fresh()->password))->toBeTrue();
});

test('35 an administrator reset applies the policy to the target, or generates a one-time password', function (): void {
    $admin = ppSuperAdmin();
    $target = ppEmployeeUser();

    $this->actingAs($admin)->patch(route('users.update', $target), [
        'name' => $target->name, 'email' => $target->email, 'status' => 'active',
        'password' => 'Kebede fresh start 2026', 'password_confirmation' => 'Kebede fresh start 2026',
    ])->assertSessionHasErrors(['password' => __('password-policy.contains_name')]);

    $this->actingAs($admin)->patch(route('users.update', $target), [
        'name' => $target->name, 'email' => $target->email, 'status' => 'active', 'generate_temporary_password' => true,
    ])->assertSessionHasNoErrors();

    $temporary = (string) session('flash.temporary_password');
    expect(Hash::check($temporary, $target->fresh()->password))->toBeTrue()
        ->and($target->fresh()->must_change_password)->toBeTrue()
        ->and(ppError($temporary))->toBeNull()   // 39: generated passwords satisfy the policy themselves
        ->and(AuditLog::query()->where('event_type', AuditEventType::TemporaryPasswordAssigned->value)->exists())->toBeTrue();
});

test('36 creating an administrator account applies the policy', function (): void {
    $this->actingAs(ppSuperAdmin())->post(route('users.store'), [
        'name' => 'Meron Alemu', 'email' => 'meron.alemu@example.test', 'status' => 'active',
        'password' => 'Meron quiet harbor 2026', 'password_confirmation' => 'Meron quiet harbor 2026',
    ])->assertSessionHasErrors(['password' => __('password-policy.contains_name')]);

    expect(User::query()->where('email', 'meron.alemu@example.test')->exists())->toBeFalse();
});

test('37 employee self-registration applies the policy against the employee record', function (): void {
    config(['security.registration_enabled' => true]);
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-55120', 'first_name' => 'Hirut', 'last_name' => 'Gebre', 'full_name' => 'ሂሩት ገብሬ',
        'status' => 'active', 'email' => 'hirut.g@example.test', 'phone' => '0922334455',
    ]);
    $otp = fn () => EmployeeRegistrationOtp::query()->create([
        'employee_id' => $employee->id, 'otp_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5), 'attempts' => 0, 'ip_address' => '127.0.0.1',
    ]);
    $session = ['registration_employee_id' => $employee->id, 'registration_employee_number' => 'EMP-55120'];

    $otp();
    $this->withSession($session)->post('/register', [
        'employee_number' => 'EMP-55120', 'otp' => '123456',
        'password' => 'Hirut morning coffee 2026', 'password_confirmation' => 'Hirut morning coffee 2026',
    ])->assertSessionHasErrors(['password' => __('password-policy.contains_name')]);
    expect(User::query()->where('email', 'hirut.g@example.test')->exists())->toBeFalse();

    $this->withSession($session)->post('/register', [
        'employee_number' => 'EMP-55120', 'otp' => '123456', 'password' => PP_GOOD, 'password_confirmation' => PP_GOOD,
    ])->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'hirut.g@example.test')->exists())->toBeTrue();
});

test('38 provider accounts get the same policy: creation, reset and their own change', function (): void {
    $admin = ppSuperAdmin();
    $serviceTypeId = ServiceType::query()->firstOrCreate(['code' => 'transport'], ['name_en' => 'Transport', 'is_active' => true])->id;

    $this->actingAs($admin)->post(route('provider-users.store'), [
        'service_type_id' => $serviceTypeId, 'name' => 'Dawit Driver', 'username' => 'dawit_driver', 'status' => 'active',
        'password' => 'password',
    ])->assertSessionHasErrors('password');

    $this->actingAs($admin)->post(route('provider-users.store'), [
        'service_type_id' => $serviceTypeId, 'name' => 'Dawit Driver', 'username' => 'dawit_driver', 'status' => 'active', 'password' => '',
    ])->assertSessionHasNoErrors();
    $account = ServiceProviderUser::query()->where('username', 'dawit_driver')->firstOrFail();
    expect(Hash::check((string) session('flash.temporary_password'), $account->password))->toBeTrue()
        ->and($account->must_change_password)->toBeTrue();

    // Provider portal: own change needs the current password and passes the policy.
    $provider = ppProviderUser();
    $this->actingAs($provider, 'provider')->patch(route('provider.portal.profile.password'), [
        'current_password' => 'Provider start phrase 77', 'password' => 'selamawit.ops harbor 26', 'password_confirmation' => 'selamawit.ops harbor 26',
    ])->assertSessionHasErrors('password');
    $this->actingAs($provider, 'provider')->patch(route('provider.portal.profile.password'), [
        'current_password' => 'Provider start phrase 77', 'password' => PP_GOOD, 'password_confirmation' => PP_GOOD,
    ])->assertSessionHasNoErrors();

    expect(Hash::check(PP_GOOD, $provider->fresh()->password))->toBeTrue()
        ->and(PasswordHistory::query()->where('authenticatable_type', $provider->getMorphClass())->count())->toBe(1);
});

test('39 the legacy shared default password can never be chosen again', function (): void {
    SystemSetting::query()->updateOrCreate(
        ['group' => 'security', 'key' => 'default_password_hash'],
        ['value' => Hash::make('Shared start phrase 2024'), 'type' => 'password', 'label_en' => 'legacy'],
    );
    app(SystemSettingsService::class)->clearCache();

    expect(ppError('Shared start phrase 2024'))->toBe(__('auth.password_cannot_be_default'));
});

test('40 45 no API or import route accepts passwords or exposes password history', function (): void {
    $offending = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/')
        && preg_match('/password|history/i', $route->uri().' '.($route->getName() ?? '')));

    expect($offending->all())->toBe([])
        ->and(collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'password-histor'))->all())->toBe([]);
});

test('41 a provider with a password someone else set must change it before using the portal', function (): void {
    $provider = ppProviderUser(['must_change_password' => true]);

    $this->actingAs($provider, 'provider')->get(route('provider.portal.dashboard'))
        ->assertRedirect(route('provider.portal.profile.show'));
    $this->actingAs($provider, 'provider')->get(route('provider.portal.profile.show'))->assertOk();
});

// ── Security (42–51) ────────────────────────────────────────────────────────

test('42 44 neither passwords nor hashes reach the audit log or page props', function (): void {
    $user = ppEmployeeUser();

    $this->actingAs($user)->from('/profile')->put(route('password.update'), [
        'current_password' => 'Initial passphrase river 11', 'password' => PP_GOOD, 'password_confirmation' => PP_GOOD,
    ])->assertSessionHasNoErrors();

    $audit = json_encode(AuditLog::query()->get()->toArray());
    expect($audit)->not->toContain(PP_GOOD)->not->toContain('Initial passphrase')->not->toContain('$argon2id$')->not->toContain('$2y$');

    $this->get(route('employee.portal'))->assertInertia(function (AssertableInertia $page): void {
        $props = json_encode($page->toArray()['props']);
        expect($props)->not->toContain('$argon2id$')->not->toContain('$2y$')->not->toContain('password_hash');
    });
});

test('43 password fields are redacted from the error log', function (): void {
    $clean = json_encode(app(ErrorLoggingService::class)->sanitizeInput([
        'password' => 'secret-one', 'password_confirmation' => 'secret-two', 'current_password' => 'secret-three',
        'new_password' => 'secret-four', 'temporary_password' => 'secret-five', 'reset_token' => 'secret-six', 'token' => 'secret-seven',
    ]));

    expect($clean)->not->toContain('secret-');
});

test('46 failed sign-ins are throttled per account and per IP (password spraying)', function (): void {
    $user = ppEmployeeUser();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong guess number '.$attempt]);
    }
    // Locked even with the right password now.
    $this->post(route('login'), ['email' => $user->email, 'password' => 'Initial passphrase river 11'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    config(['security.login.per_ip_failures' => 6]);
    foreach (range(1, 6) as $n) {
        $this->post(route('login'), ['email' => "sprayed{$n}@example.test", 'password' => 'Summer2026!!!!!!']);
    }
    $this->post(route('login'), ['email' => 'fresh.target@example.test', 'password' => 'Summer2026!!!!!!'])
        ->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->not->toBe(trans('auth.failed'));  // throttled, not just "wrong password"
});

test('47 the reset-link endpoint is throttled and never reveals whether an account exists', function (): void {
    Notification::fake();
    $user = ppEmployeeUser();

    $known = $this->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.test']);
    expect($known->getSession()->get('status'))->toBe($unknown->getSession()->get('status'))->toBe(__('password-policy.reset_link_sent'));
    $unknown->assertSessionDoesntHaveErrors();

    foreach (range(1, 3) as $n) {
        $this->post(route('password.email'), ['email' => "x{$n}@example.test"]);
    }
    $this->post(route('password.email'), ['email' => 'x9@example.test'])->assertStatus(429);
});

test('48 changing your own password requires the current one', function (): void {
    $user = ppEmployeeUser();

    $this->actingAs($user)->from('/profile')->put(route('password.update'), [
        'password' => PP_GOOD, 'password_confirmation' => PP_GOOD,
    ])->assertSessionHasErrors('current_password');
});

test('50 reset tokens expire', function (): void {
    Notification::fake();
    $user = ppEmployeeUser();
    $this->post(route('password.email'), ['email' => $user->email]);
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();
    $this->post(route('password.store'), ['token' => $token, 'email' => $user->email, 'password' => PP_GOOD, 'password_confirmation' => PP_GOOD])
        ->assertSessionHasErrors('email');
});

test('51 the holder is notified of a change, without the password', function (): void {
    Notification::fake();
    $user = ppEmployeeUser();

    app(PasswordLifecycle::class)->change($user, PP_GOOD, AuditEventType::UserPasswordChanged);

    Notification::assertSentTo($user, PasswordSecurityNotification::class, function (PasswordSecurityNotification $notification) use ($user) {
        $mail = json_encode($notification->toMail($user)->toArray()).json_encode($notification->toArray($user));

        return ! str_contains($mail, PP_GOOD) && ! str_contains($mail, '$argon2id$') && in_array('database', $notification->via($user), true);
    });
});

// ── Hashing migration ───────────────────────────────────────────────────────

test('a legacy bcrypt hash still signs in and is upgraded to Argon2id on that sign-in', function (): void {
    $user = ppEmployeeUser();
    DB::table('users')->where('id', $user->id)->update(['password' => password_hash('Initial passphrase river 11', PASSWORD_BCRYPT)]);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'Initial passphrase river 11']);

    $this->assertAuthenticatedAs($user);
    expect(DB::table('users')->where('id', $user->id)->value('password'))->toStartWith('$argon2id$');
});

test('a legacy bcrypt provider hash is upgraded on provider sign-in', function (): void {
    $provider = ppProviderUser();
    DB::table('provider_users')->where('id', $provider->id)->update(['password' => password_hash('Provider start phrase 77', PASSWORD_BCRYPT)]);

    $this->post(route('provider.portal.login.store'), ['identifier' => 'selamawit.ops', 'password' => 'Provider start phrase 77']);

    $this->assertAuthenticatedAs($provider, 'provider');
    expect(DB::table('provider_users')->where('id', $provider->id)->value('password'))->toStartWith('$argon2id$');
});

test('the policy cannot be configured below the approved floor or back to composition rules', function (): void {
    Role::findOrCreate('Super Admin', 'web');
    $payload = [
        'password_min_length' => 8, 'password_max_length' => 128, 'password_history_count' => 30,
        'password_block_personal_info' => true, 'password_block_common' => true, 'password_breach_check' => true,
    ];

    $this->actingAs(ppSuperAdmin())->patch(route('system-settings.security.update'), $payload)
        ->assertSessionHasErrors(['password_min_length', 'password_history_count']);
});
