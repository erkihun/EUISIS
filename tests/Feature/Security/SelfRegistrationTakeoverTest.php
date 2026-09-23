<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\User;

beforeEach(function (): void {
    config()->set('security.registration_enabled', true);

    $this->target = Employee::query()->create([
        'employee_number' => 'TAKEOVER-0001',
        'first_name' => 'Real',
        'last_name' => 'Employee',
        'full_name' => 'Real Employee',
        'email' => 'real.employee@example.test',
        'phone' => '+251911555111',
        'status' => 'active',
    ]);
});

test('SEC2-005: knowing an employee number cannot open that employee account', function (): void {
    $this->post('/register', [
        'employee_number' => 'TAKEOVER-0001',
        'otp' => '123456',
        'password' => 'Attacker!Password#2026',
        'password_confirmation' => 'Attacker!Password#2026',
    ])->assertSessionHasErrors('otp');

    expect(User::query()->where('email', 'real.employee@example.test')->exists())->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

test('an existing employee account cannot request another registration code', function (): void {
    User::factory()->create([
        'email' => 'real.employee@example.test',
        'employee_reference' => 'TAKEOVER-0001',
    ]);

    $this->post(route('register.send-otp'), [
        'employee_number' => 'TAKEOVER-0001',
    ])->assertSessionHasErrors('employee_number');
});

test('registration is refused entirely when the feature is disabled', function (): void {
    config()->set('security.registration_enabled', false);

    $this->post('/register', [
        'employee_number' => 'TAKEOVER-0001',
        'otp' => '123456',
        'password' => 'Attacker!Password#2026',
        'password_confirmation' => 'Attacker!Password#2026',
    ])->assertRedirect(route('login'));

    expect(User::query()->where('email', 'real.employee@example.test')->exists())->toBeFalse();
});
