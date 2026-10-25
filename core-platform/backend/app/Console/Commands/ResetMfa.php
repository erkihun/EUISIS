<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\MultiFactor;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Resets an account's second factor from the server, for the case no
 * administrator can sign in to do it (the last city-wide administrator lost
 * their device). The holder enrols again at the next sign-in; the reset is
 * audited with its reason, and every sign-in of the account ends.
 */
class ResetMfa extends Command
{
    protected $signature = 'core:reset-mfa {email} {--reason= : Why the second factor is reset (required)}';

    protected $description = 'Reset the multi-factor authentication of a Core account';

    public function handle(MultiFactor $mfa): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 5) {
            $this->error('Give the reason with --reason (at least 5 characters).');

            return self::FAILURE;
        }
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('No account with that e-mail.');

            return self::FAILURE;
        }
        $mfa->reset($user, null, 'Server reset: '.$reason);
        $this->info("The second factor of {$user->email} was reset; the holder enrols again at the next sign-in.");

        return self::SUCCESS;
    }
}
