<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Models\CafeteriaProvider;
use App\Models\Provider;
use App\Models\ProviderPasswordResetCode;
use App\Models\ProviderType;
use App\Models\ProviderUser;
use App\Models\ServiceType;
use App\Notifications\ProviderPasswordResetCodeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

const PR_NEW_PASSWORD = 'violet harbor lanterns drift slowly';

beforeEach(function (): void {
    app()->setLocale('en');
    Notification::fake();
});

function providerResetAccount(array $overrides = []): ProviderUser
{
    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria Service', 'is_active' => true]);
    $providerType = ProviderType::query()->firstOrCreate(['code' => 'CAFETERIA'], ['name_en' => 'Cafeteria', 'is_active' => true]);

    $provider = Provider::query()->create([
        'provider_code' => 'PR-'.Str::upper(Str::random(6)),
        'provider_type_id' => $providerType->id,
        'name_en' => 'Reset Test Cafeteria',
        'status' => 'active',
    ]);

    DB::table('provider_services')->insert([
        'id' => (string) Str::uuid7(), 'provider_id' => $provider->id, 'service_type_id' => $serviceType->id,
        'status' => 'active', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    CafeteriaProvider::query()->create(['provider_id' => $provider->id, 'code' => $provider->provider_code, 'name_en' => 'Reset Test Cafeteria', 'is_active' => true]);

    return ProviderUser::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Reset Operator',
        'email' => 'reset-operator@example.test',
        'username' => 'reset-operator',
        'phone_number' => '0911 000 111',
        'password' => Hash::make('Old provider phrase 42'),
        'provider_role' => 'operator',
        'status' => 'active',
        'portal_enabled' => true,
        ...$overrides,
    ]);
}

/** Records SMS instead of sending; `configured: false` behaves like an installation without SMS. */
function providerResetSms(bool $configured = true): object
{
    $gateway = new class($configured) implements SmsGateway
    {
        /** @var list<array{to: string, message: string}> */
        public array $sent = [];

        public function __construct(private readonly bool $configured) {}

        public function send(string $phoneNumber, string $message): bool
        {
            $this->sent[] = ['to' => $phoneNumber, 'message' => $message];

            return $this->configured;
        }

        public function isConfigured(): bool
        {
            return $this->configured;
        }
    };

    app()->instance(SmsGateway::class, $gateway);

    return $gateway;
}

function providerResetEmailedCode(ProviderUser $user): string
{
    $code = null;
    Notification::assertSentTo($user, ProviderPasswordResetCodeNotification::class, function ($notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    return (string) $code;
}

function providerResetSubmit(string $code, string $password = PR_NEW_PASSWORD): TestResponse
{
    return test()->from(route('provider.portal.password.request'))->post(route('provider.portal.password.reset'), [
        'code' => $code,
        'password' => $password,
        'password_confirmation' => $password,
    ]);
}

test('the forgot-password page renders for guests', function (): void {
    providerResetSms();

    $this->get(route('provider.portal.password.request'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ProviderPortal/Auth/ForgotPassword')
            ->where('pending', null)
            ->where('smsAvailable', true)
        );
});

test('a provider resets their password with a code sent to their email', function (): void {
    $user = providerResetAccount(['must_change_password' => true]);

    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test'])
        ->assertRedirect(route('provider.portal.password.request'));

    $this->get(route('provider.portal.password.request'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending.identifier', 'reset-operator@example.test')
            ->where('pending.channel', 'email')
        );

    providerResetSubmit(providerResetEmailedCode($user))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('provider.portal.login'))
        ->assertSessionHas('status', __('provider-portal.reset_complete'));

    $user->refresh();
    expect(Hash::check(PR_NEW_PASSWORD, $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and(ProviderPasswordResetCode::query()->whereNull('used_at')->count())->toBe(0);

    $this->post(route('provider.portal.login.store'), ['identifier' => 'reset-operator', 'password' => PR_NEW_PASSWORD])
        ->assertRedirect(route('provider.portal.dashboard'));
    $this->assertAuthenticatedAs($user, 'provider');
});

test('a provider resets their password with a code sent by SMS, whatever format they type the number in', function (): void {
    $sms = providerResetSms();
    $user = providerResetAccount();

    $this->post(route('provider.portal.password.send'), ['identifier' => '+251 911-000-111'])->assertSessionHasNoErrors();

    expect($sms->sent)->toHaveCount(1)
        ->and($sms->sent[0]['to'])->toBe('0911 000 111');
    preg_match('/\b(\d{6})\b/', $sms->sent[0]['message'], $match);

    providerResetSubmit($match[1])->assertSessionHasNoErrors()->assertRedirect(route('provider.portal.login'));

    expect(Hash::check(PR_NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
});

test('an unknown email gets the same answer and no code', function (): void {
    providerResetAccount();

    $this->post(route('provider.portal.password.send'), ['identifier' => 'nobody@example.test'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('provider.portal.password.request'));

    Notification::assertNothingSent();

    providerResetSubmit('123456')->assertSessionHasErrors(['code' => __('provider-portal.reset_code_invalid')]);
});

test('a wrong code is refused and five wrong tries use the code up', function (): void {
    $user = providerResetAccount();
    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test']);
    $code = providerResetEmailedCode($user);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, ProviderPasswordResetCode::MAX_ATTEMPTS) as $ignored) {
        providerResetSubmit($wrong)->assertSessionHasErrors(['code' => __('provider-portal.reset_code_invalid')]);
    }

    providerResetSubmit($code)->assertSessionHasErrors('code');
    expect(Hash::check('Old provider phrase 42', $user->fresh()->password))->toBeTrue();
});

test('an expired code is refused', function (): void {
    $user = providerResetAccount();
    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test']);
    $code = providerResetEmailedCode($user);

    $this->travel(ProviderPasswordResetCode::TTL_MINUTES + 1)->minutes();

    providerResetSubmit($code)->assertSessionHasErrors(['code' => __('provider-portal.reset_code_invalid')]);
});

test('a second request within a minute sends no new code; a later one replaces the first', function (): void {
    $user = providerResetAccount();

    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test']);
    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test']);
    Notification::assertSentToTimes($user, ProviderPasswordResetCodeNotification::class, 1);
    $first = providerResetEmailedCode($user);

    $this->travel(ProviderPasswordResetCode::RESEND_SECONDS + 1)->seconds();
    Notification::fake();
    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test']);
    $second = providerResetEmailedCode($user);

    if ($first !== $second) {
        providerResetSubmit($first)->assertSessionHasErrors('code');
    }
    providerResetSubmit($second)->assertSessionHasNoErrors();
});

test('phone reset is refused for everyone when SMS is not set up', function (): void {
    providerResetSms(configured: false);
    providerResetAccount();

    $this->post(route('provider.portal.password.send'), ['identifier' => '0911000111'])
        ->assertSessionHasErrors(['identifier' => __('provider-portal.reset_sms_unavailable')]);
});

test('something that is neither an email nor a phone number is refused', function (string $identifier): void {
    $this->post(route('provider.portal.password.send'), ['identifier' => $identifier])
        ->assertSessionHasErrors(['identifier' => __('provider-portal.reset_invalid_identifier')]);
})->with(['reset-operator', 'not@an', '12']);

test('no code is sent to a suspended or portal-disabled account', function (array $overrides): void {
    providerResetAccount($overrides);

    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test'])
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
})->with([
    'suspended' => [['status' => 'suspended']],
    'portal disabled' => [['portal_enabled' => false]],
]);

test('a phone number shared by two accounts sends no code', function (): void {
    $sms = providerResetSms();
    providerResetAccount();
    providerResetAccount(['email' => 'second@example.test', 'username' => 'second-operator', 'phone_number' => '+251911000111']);

    $this->post(route('provider.portal.password.send'), ['identifier' => '0911000111'])->assertSessionHasNoErrors();

    expect($sms->sent)->toBe([]);
});

test('account-specific password rules apply after the code, and the code survives a rejected password', function (): void {
    $user = providerResetAccount();
    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test']);
    $code = providerResetEmailedCode($user);

    providerResetSubmit($code, 'Old provider phrase 42')->assertSessionHasErrors('password');

    providerResetSubmit($code)->assertSessionHasNoErrors()->assertRedirect(route('provider.portal.login'));
});

test('a reset lifts the sign-in lockout the forgotten password caused', function (): void {
    $user = providerResetAccount();

    foreach (range(1, 5) as $ignored) {
        $this->post(route('provider.portal.login.store'), ['identifier' => 'reset-operator', 'password' => 'wrong guess']);
    }
    // Past the per-minute route limit, still inside the lockout window.
    $this->travel(2)->minutes();
    $this->post(route('provider.portal.login.store'), ['identifier' => 'reset-operator', 'password' => 'Old provider phrase 42'])
        ->assertSessionHasErrors('identifier');
    $this->assertGuest('provider');

    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test']);
    providerResetSubmit(providerResetEmailedCode($user))->assertSessionHasNoErrors();

    $this->post(route('provider.portal.login.store'), ['identifier' => 'reset-operator', 'password' => PR_NEW_PASSWORD])
        ->assertRedirect(route('provider.portal.dashboard'));
    $this->assertAuthenticatedAs($user, 'provider');
});

test('submitting a code without a pending request starts over', function (): void {
    providerResetSubmit('123456')
        ->assertRedirect(route('provider.portal.password.request'))
        ->assertSessionHasErrors(['identifier' => __('provider-portal.reset_start_again')]);
});

test('cancelling forgets the pending request', function (): void {
    providerResetAccount();
    $this->post(route('provider.portal.password.send'), ['identifier' => 'reset-operator@example.test']);

    $this->delete(route('provider.portal.password.cancel'))->assertRedirect(route('provider.portal.password.request'));

    $this->get(route('provider.portal.password.request'))
        ->assertInertia(fn (Assert $page) => $page->where('pending', null));
});

test('the login page shows the reset confirmation', function (): void {
    $this->withSession(['status' => __('provider-portal.reset_complete')])
        ->get(route('provider.portal.login'))
        ->assertInertia(fn (Assert $page) => $page->where('status', __('provider-portal.reset_complete')));
});

test('a signed-in provider is sent to their portal instead of the reset page', function (): void {
    $user = providerResetAccount();

    $this->actingAs($user, 'provider')
        ->get(route('provider.portal.password.request'))
        ->assertRedirect(route('provider.portal.dashboard'));
});
