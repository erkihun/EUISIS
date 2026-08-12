<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Security\MfaRequirementService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'employee_reference',
        'default_organization_id',
        'cafeteria_provider_id',
        'provider_portal_enabled',
        'provider_portal_activated_at',
        'user_type',
        'status',
        'last_login_at',
        'is_demo',
        'profile_photo_path',
        'national_id',
        'national_id_hash',
        'phone_number',
        'gender',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_enabled',
        'two_factor_last_used_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'national_id',
        'national_id_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'provider_portal_activated_at' => 'datetime',
            'password' => 'hashed',
            'is_demo' => 'bool',
            'provider_portal_enabled' => 'boolean',
            // Sensitive PII — encrypted at rest. national_id_hash is a SHA-256
            // hash used for uniqueness lookups (see EncryptableUserFields).
            'national_id' => 'encrypted',
            'phone_number' => 'encrypted',
            // Multi-Factor Authentication (TOTP)
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Maintain a deterministic hash of the encrypted national_id so we can
        // run uniqueness lookups without leaking the plaintext.
        static::saving(function (User $user): void {
            if ($user->isDirty('national_id')) {
                $value = $user->getAttribute('national_id');
                $user->national_id_hash = $value !== null && $value !== ''
                    ? hash('sha256', (string) $value)
                    : null;
            }
        });
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('Super Admin');
    }

    public function profilePhotoUrl(): ?string
    {
        if (! $this->profile_photo_path) {
            return null;
        }

        return '/storage/'.$this->profile_photo_path;
    }

    public function initials(): string
    {
        $words = array_filter(explode(' ', $this->name ?? ''));
        $letters = array_map(fn (string $w) => mb_strtoupper(mb_substr($w, 0, 1)), $words);

        return implode('', array_slice($letters, 0, 2));
    }

    /**
     * Employee profile linked to this user, matched by email address.
     * Falls back to employee_reference → employee_number if email yields no match.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'email', 'email');
    }

    public function organizationScopes(): HasMany
    {
        return $this->hasMany(UserOrganizationScope::class);
    }

    public function cafeteriaProviders(): BelongsToMany
    {
        return $this->belongsToMany(CafeteriaProvider::class, 'cafeteria_provider_assignments')
            ->withPivot(['role', 'provider_role', 'is_active', 'assigned_by', 'effective_from', 'effective_to'])
            ->withTimestamps();
    }

    public function primaryCafeteriaProvider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function hasProviderPortalAccess(): bool
    {
        $today = Carbon::today()->toDateString();
        $hasPortalGrant = $this->provider_portal_enabled
            || $this->user_type === 'provider'
            || $this->can('cafeteria-portal.login')
            || $this->can('cafeteria-portal.viewDashboard')
            || $this->can('cafeteria-portal.impersonate')
            || $this->can('cafeteria-portal.view');

        return $this->isActive()
            && $hasPortalGrant
            && $this->cafeteriaProviders()
                ->wherePivot('is_active', true)
                ->where(function ($query) use ($today): void {
                    $query->whereNull('cafeteria_provider_assignments.effective_from')
                        ->orWhere('cafeteria_provider_assignments.effective_from', '<=', $today);
                })
                ->where(function ($query) use ($today): void {
                    $query->whereNull('cafeteria_provider_assignments.effective_to')
                        ->orWhere('cafeteria_provider_assignments.effective_to', '>=', $today);
                })
                ->exists();
    }

    /**
     * Returns the set of organization IDs accessible to this user based on active scopes.
     * An empty array means "all organizations" (Super Admin or City Admin).
     *
     * @return string[]
     */
    public function accessibleOrganizationIds(): array
    {
        return app(OrganizationScopeService::class)->accessibleOrganizationIds($this)->all();
    }

    // ── MFA helpers ────────────────────────────────────────────────────────

    /**
     * True when the user must use multi-factor authentication — either via the
     * env baseline config('security.mfa_required_roles') or the role-based
     * rules configured in System Settings > Security.
     */
    public function requiresMfa(): bool
    {
        return app(MfaRequirementService::class)->requiresMfa($this);
    }

    /**
     * True when the user has finished MFA enrolment (secret saved + confirmed).
     */
    public function hasMfaEnabled(): bool
    {
        return (bool) $this->two_factor_enabled
            && $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Replace the stored recovery codes with a freshly generated set. Codes
     * are returned in plaintext to the caller (to show once) but persisted
     * via the encrypted cast.
     *
     * @return array<int, string>
     */
    public function regenerateMfaRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // 10 hex chars in two groups of 5 — short enough to be usable
            $raw = bin2hex(random_bytes(5));
            $codes[] = strtoupper(substr($raw, 0, 5).'-'.substr($raw, 5, 5));
        }

        // Persist hashed codes so a DB leak does not give the codes away.
        $this->two_factor_recovery_codes = json_encode(array_map(
            fn (string $code): string => Hash::make($code),
            $codes,
        ));

        return $codes;
    }

    /**
     * Verify a one-time recovery code. On success the code is consumed and
     * removed from the stored list, then the model is saved.
     */
    public function consumeMfaRecoveryCode(string $code): bool
    {
        $raw = $this->two_factor_recovery_codes;
        if (! $raw) {
            return false;
        }

        $codes = json_decode($raw, true) ?: [];
        foreach ($codes as $index => $hashed) {
            if (Hash::check($code, $hashed)) {
                unset($codes[$index]);
                $this->two_factor_recovery_codes = json_encode(array_values($codes));
                $this->save();

                return true;
            }
        }

        return false;
    }
}
