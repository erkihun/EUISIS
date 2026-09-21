<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\User;

/**
 * Phase-2 SEC2-005: account creation against another person's identity.
 *
 * /register asks only for an employee_number, then builds the account from
 * the Employee record it finds — name, email, employee_reference — and signs
 * the caller straight in. An employee number is a business identifier printed
 * on ID cards and used in CSV imports, not a secret, so possession of one
 * proves nothing about who is calling.
 */
beforeEach(function (): void {
    config()->set('security.registration_enabled', true);

    $this->target = Employee::query()->create([
        'employee_number' => 'TAKEOVER-0001',
        'first_name' => 'Real',
        'last_name' => 'Employee',
        'full_name' => 'Real Employee',
        'email' => 'real.employee@example.test',
        'status' => 'active',
    ]);
});

test('SEC2-005: knowing an employee number is enough to open that employee account', function (): void {
    expect(User::query()->where('email', 'real.employee@example.test')->exists())->toBeFalse();

    $this->post('/register', [
        'employee_number' => 'TAKEOVER-0001',
        'password' => 'Attacker!Password#2026',
        'password_confirmation' => 'Attacker!Password#2026',
    ])->assertRedirect(route('employee.portal'));

    $created = User::query()->where('email', 'real.employee@example.test')->first();

    // The attacker now holds an account bound to the employee's identity,
    // with a password only the attacker knows, and is already signed in.
    expect($created)->not->toBeNull()
        ->and($created->employee_reference)->toBe('TAKEOVER-0001')
        ->and(auth()->id())->toBe($created->id);
});

test('the same employee cannot be registered twice', function (): void {
    $payload = [
        'employee_number' => 'TAKEOVER-0001',
        'password' => 'Attacker!Password#2026',
        'password_confirmation' => 'Attacker!Password#2026',
    ];

    $this->post('/register', $payload);
    auth()->logout();

    $this->post('/register', $payload)->assertSessionHasErrors('employee_number');

    expect(User::query()->where('email', 'real.employee@example.test')->count())->toBe(1);
});

/* The one control that does hold: the feature can be switched off. */
test('registration is refused entirely when the feature is disabled', function (): void {
    config()->set('security.registration_enabled', false);

    $this->post('/register', [
        'employee_number' => 'TAKEOVER-0001',
        'password' => 'Attacker!Password#2026',
        'password_confirmation' => 'Attacker!Password#2026',
    ])->assertRedirect(route('login'));

    expect(User::query()->where('email', 'real.employee@example.test')->exists())->toBeFalse();
});
