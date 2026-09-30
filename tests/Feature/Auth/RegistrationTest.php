<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Models\Employee;
use App\Models\EmployeeRegistrationOtp;
use App\Models\User;
use App\Notifications\EmployeeRegistrationOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->sms = new class implements SmsGateway
    {
        public array $sent = [];

        public function send(string $phoneNumber, string $message): bool
        {
            $this->sent[] = ['phone' => $phoneNumber, 'message' => $message];

            return true;
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };

    app()->instance(SmsGateway::class, $this->sms);
});

test('registration screen redirects to login when registration is disabled', function (): void {
    config(['security.registration_enabled' => false]);

    $this->get('/register')->assertRedirect('/login');
});

test('registration screen can be rendered when registration is enabled', function (): void {
    config(['security.registration_enabled' => true]);

    $this->get('/register')->assertOk();
});

test('an active employee receives a registration otp by email and phone in their language', function (string $locale): void {
    config(['security.registration_enabled' => true]);
    Notification::fake();

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-OTP-1',
        'full_name' => 'Verified Employee',
        'first_name' => 'Verified',
        'last_name' => 'Employee',
        'email' => 'verified@example.test',
        'phone' => '+251911000111',
        'status' => 'active',
    ]);

    $this->withUnencryptedCookie('euisis_locale', $locale)->post(route('register.send-otp'), [
        'employee_number' => $employee->employee_number,
    ])->assertSessionHasNoErrors()->assertSessionHas('status', trans('auth.registration_otp_sent', [], $locale));

    Notification::assertSentOnDemand(EmployeeRegistrationOtpNotification::class, function ($notification, $channels, $notifiable) use ($locale): bool {
        $mail = $notification->toMail($notifiable);

        return $mail->subject === trans('auth.registration_otp_subject', [], $locale)
            && $mail->greeting === trans('auth.registration_otp_greeting', [], $locale)
            && in_array(trans('auth.registration_otp_intro', [], $locale), $mail->introLines, true)
            && $this->sms->sent[0]['message'] === trans('auth.registration_otp_sms', [
                'code' => $notification->code,
                'minutes' => EmployeeRegistrationOtp::TTL_MINUTES,
            ], $locale);
    });
    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['phone'])->toBe('+251911000111')
        ->and(EmployeeRegistrationOtp::query()->where('employee_id', $employee->id)->count())->toBe(1);
})->with(['en', 'am']);

test('registration validation uses localized field names', function (string $locale): void {
    config(['security.registration_enabled' => true]);

    $this->withUnencryptedCookie('euisis_locale', $locale)->post('/register', [])
        ->assertSessionHasErrors(collect(['employee_number', 'otp', 'password'])
            ->mapWithKeys(fn (string $field): array => [$field => trans('validation.required', [
                'attribute' => trans('validation.attributes.'.$field, [], $locale),
            ], $locale)])->all());

    $this->post(route('register.send-otp'), [])->assertSessionHasErrors([
        'employee_number' => trans('validation.required', [
            'attribute' => trans('validation.attributes.employee_number', [], $locale),
        ], $locale),
    ]);
})->with(['en', 'am']);

test('amharic registration messages do not fall back to english', function (): void {
    $english = require lang_path('en/auth.php');
    $amharic = require lang_path('am/auth.php');

    foreach ($english as $key => $message) {
        if (str_starts_with($key, 'registration_otp_') || str_starts_with($key, 'employee_')) {
            expect($amharic[$key])->not->toBe($message)->toMatch('/[\x{1200}-\x{137F}]/u');
        }
    }
});

test('a valid registration otp verifies the employee and creates the account', function (): void {
    config(['security.registration_enabled' => true]);
    Notification::fake();

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-OTP-2',
        'full_name' => 'Verified Employee',
        'first_name' => 'Verified',
        'last_name' => 'Employee',
        'email' => 'verified2@example.test',
        'phone' => '+251911000222',
        'status' => 'active',
    ]);

    $this->post(route('register.send-otp'), ['employee_number' => $employee->employee_number]);

    EmployeeRegistrationOtp::query()
        ->where('employee_id', $employee->id)
        ->latest('created_at')
        ->firstOrFail()
        ->forceFill(['otp_hash' => Hash::make('123456')])
        ->save();

    $this->post('/register', [
        'employee_number' => $employee->employee_number,
        'otp' => '123456',
        'password' => 'Secure!Password#2026',
        'password_confirmation' => 'Secure!Password#2026',
    ])->assertRedirect(route('employee.portal'));

    $user = User::query()->where('employee_reference', $employee->employee_number)->first();

    expect($user)->not->toBeNull()
        ->and(auth()->id())->toBe($user->id)
        ->and(EmployeeRegistrationOtp::query()->latest('created_at')->first()->verified_at)->not->toBeNull();
});

test('registration cannot finish without requesting and verifying an otp', function (): void {
    config(['security.registration_enabled' => true]);

    $response = $this->post('/register', [
        'employee_number' => 'EMP-UNKNOWN',
        'otp' => '123456',
        'password' => 'Secure!Password#2026',
        'password_confirmation' => 'Secure!Password#2026',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('otp');
});

test('inactive employees cannot request a registration otp', function (): void {
    config(['security.registration_enabled' => true]);

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-INACTIVE',
        'full_name' => 'Inactive Employee',
        'first_name' => 'Inactive',
        'last_name' => 'Employee',
        'email' => 'inactive@example.test',
        'phone' => '+251911000333',
        'status' => 'suspended',
    ]);

    $this->post(route('register.send-otp'), [
        'employee_number' => $employee->employee_number,
    ])->assertSessionHasErrors('employee_number');
});
