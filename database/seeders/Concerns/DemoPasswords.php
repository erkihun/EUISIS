<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Security\Passwords\PasswordPolicy;
use Illuminate\Support\Facades\Hash;

/**
 * Passwords for seeded demo accounts.
 *
 * The well-known "password" is used only on a developer machine or in the
 * test suite. Anywhere else each demo account gets a unique generated
 * one-time password — printed once to the operator running the seeder — that
 * must be changed at first sign-in.
 */
trait DemoPasswords
{
    /** @return array<string, mixed> */
    protected function demoPasswordAttributes(string $email): array
    {
        if (app()->environment(['local', 'testing'])) {
            return ['password' => Hash::make('password')];
        }

        $temporary = app(PasswordPolicy::class)->generateTemporaryPassword();
        $this->command?->warn("One-time password for {$email}: {$temporary} (must be changed at first sign-in; shown only now)");

        return ['password' => Hash::make($temporary), 'must_change_password' => true];
    }
}
