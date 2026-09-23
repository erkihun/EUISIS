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

test('an active employee receives a registration otp by email and phone', function (): void {
    config(['security.registration_enabled' => true]);
    Notification::fake();

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-OTP-1',
        'full_name' => 'Verified Employee',
        'email' => 'verified@example.test',
        'phone' => '+251911000111',
        'status' => 'active',
    ]);

    $this->post(route('register.send-otp'), [
        'employee_number' => $employee->employee_number,
    ])->assertSessionHasNoErrors()->assertSessionHas('status');

    Notification::assertSentOnDemand(EmployeeRegistrationOtpNotification::class);
    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['phone'])->toBe('+251911000111')
        ->and(EmployeeRegistrationOtp::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('a valid registration otp verifies the employee and creates the account', function (): void {
    config(['security.registration_enabled' => true]);
    Notification::fake();

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-OTP-2',
        'full_name' => 'Verified Employee',
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
        'email' => 'inactive@example.test',
        'phone' => '+251911000333',
        'status' => 'suspended',
    ]);

    $this->post(route('register.send-otp'), [
        'employee_number' => $employee->employee_number,
    ])->assertSessionHasErrors('employee_number');
});
