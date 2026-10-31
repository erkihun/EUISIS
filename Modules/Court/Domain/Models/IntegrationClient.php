<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A system that calls the /api/v1/integration routes (today: EUISIS).
 * Its token is stored as a SHA-256 hash only and is never a Sanctum token,
 * so the person-facing API (/api/v1/cases …) can never accept it.
 */
class IntegrationClient extends Model
{
    protected $table = 'court_integration_clients';

    public const ABILITY_REFERRALS_CREATE = 'referrals:create';

    public const ABILITY_REFERRALS_READ = 'referrals:read';

    /** Read an employee's court summary for the EUISIS employee portal (Flow 4). */
    public const ABILITY_PEOPLE_READ = 'people:read';

    /** Ask for a one-time ticket that signs an employee into the applicant portal (Flow 4). */
    public const ABILITY_PEOPLE_SIGN_IN = 'people:sign-in';

    /** File a case for an employee from the EUISIS employee portal (Flow 5). */
    public const ABILITY_PEOPLE_FILE = 'people:file';

    /** Read the letters to deliver to EUISIS institutions (Flow 5). */
    public const ABILITY_LETTERS_READ = 'letters:read';

    /** Work on an employee's own case from the EUISIS employee portal: messages, files, replies, corrections (Flow 6). */
    public const ABILITY_PEOPLE_ACT = 'people:act';

    /** Read the cases filed against EUISIS institutions, and an institution's view of one (Flow 7). */
    public const ABILITY_INSTITUTIONS_READ = 'institutions:read';

    /** Ask, for an institution, for a closed case's record to take to the appellate court (Flow 7). */
    public const ABILITY_INSTITUTIONS_ACT = 'institutions:act';

    /** What a token issued by `euisis:integration-token` may do. */
    public const DEFAULT_ABILITIES = [
        self::ABILITY_REFERRALS_CREATE, self::ABILITY_REFERRALS_READ,
        self::ABILITY_PEOPLE_READ, self::ABILITY_PEOPLE_SIGN_IN, self::ABILITY_PEOPLE_FILE,
        self::ABILITY_PEOPLE_ACT, self::ABILITY_LETTERS_READ,
        self::ABILITY_INSTITUTIONS_READ, self::ABILITY_INSTITUTIONS_ACT,
    ];

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'abilities' => 'array',
        'allowed_ips' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** A new random token for this client; returns the plain text, shown once. */
    public function issueToken(): string
    {
        $plain = 'cms_'.Str::random(48);
        $this->forceFill(['token_hash' => hash('sha256', $plain), 'revoked_at' => null])->save();

        return $plain;
    }

    public static function findByToken(string $plain): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }

    public function hasAbility(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function allowsIp(?string $ip): bool
    {
        $allowed = array_values(array_filter($this->allowed_ips ?? []));

        return $allowed === [] || ($ip !== null && in_array($ip, $allowed, true));
    }
}
