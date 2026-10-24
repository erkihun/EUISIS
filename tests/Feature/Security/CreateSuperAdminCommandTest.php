<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/*
 * user:create-super-admin, the console path to the first administrator of an
 * installation. Hosting panels split a command on spaces, so the name is
 * accepted as several words without quotes.
 */

it('creates a named Super Admin with a one-time password that must be changed', function (): void {
    Role::findOrCreate('Super Admin', 'web');

    $exit = Artisan::call('user:create-super-admin', ['email' => 'First.Admin@Example.test', 'name' => ['Erkihun', 'Bewket', 'Yismawki']]);
    $output = Artisan::output();

    $user = User::query()->where('email', 'first.admin@example.test')->firstOrFail();
    preg_match('/One-time password: (\S+)/', $output, $m);

    expect($exit)->toBe(0)
        ->and($user->name)->toBe('Erkihun Bewket Yismawki')
        ->and($user->hasRole('Super Admin'))->toBeTrue()
        ->and($user->status)->toBe('active')
        ->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check($m[1], $user->password))->toBeTrue()
        ->and(AuditLog::query()->where('event_type', AuditEventType::UserCreated->value)->where('auditable_id', $user->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->get()->pluck('new_values')->toJson())->not->toContain($m[1]);
});

it('never changes an existing account and needs the role to exist', function (): void {
    $this->artisan('user:create-super-admin', ['email' => 'admin@example.test', 'name' => ['Admin']])
        ->expectsOutputToContain('The Super Admin role does not exist')
        ->assertExitCode(1);
    expect(User::query()->where('email', 'admin@example.test')->exists())->toBeFalse();

    Role::findOrCreate('Super Admin', 'web');
    $existing = User::factory()->create(['email' => 'admin@example.test']);
    $this->artisan('user:create-super-admin', ['email' => 'ADMIN@example.test', 'name' => ['Someone', 'Else']])
        ->expectsOutputToContain('already exists')
        ->assertExitCode(1);
    expect($existing->fresh()->hasRole('Super Admin'))->toBeFalse()
        ->and($existing->fresh()->name)->toBe($existing->name);

    $this->artisan('user:create-super-admin', ['email' => 'not-an-email', 'name' => ['Admin']])->assertExitCode(1);
});

it('refuses to seed demo accounts in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new UserSeeder)->run())->toThrow(RuntimeException::class, 'user:create-super-admin');
    expect(User::query()->where('email', 'super.admin@demo.local')->exists())->toBeFalse();
});
