<?php

declare(strict_types=1);

namespace Database\Factories\Court;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Court\Domain\Models\CourtStaffMember;

/**
 * A court staff member with its platform account (court-ms UserFactory). The
 * account is created first and lends its id; sign-in fields a test passes
 * (password, MFA) go to the account, which owns them now (ADR 0020).
 *
 * @extends Factory<CourtStaffMember>
 */
final class CourtStaffMemberFactory extends Factory
{
    protected $model = CourtStaffMember::class;

    private const ACCOUNT_ONLY = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'mfa_confirmed_at'];

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'status' => 'active',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CourtStaffMember $member): void {
            $attributes = $member->getAttributes();
            $accountOnly = array_intersect_key($attributes, array_flip(self::ACCOUNT_ONLY));
            foreach (array_keys($accountOnly) as $key) {
                $member->offsetUnset($key);
            }

            if ($member->getKey() === null) {
                $account = User::factory()->create([
                    'name' => $member->name,
                    'email' => $member->email,
                    ...array_intersect_key($accountOnly, array_flip(['password'])),
                ]);
                $member->setAttribute('id', $account->getKey());
            } elseif (isset($accountOnly['password'])) {
                User::query()->whereKey($member->getKey())->first()?->forceFill(['password' => $accountOnly['password']])->save();
            }
        });
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }
}
