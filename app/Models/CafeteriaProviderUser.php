<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Dedicated portal credential model for cafeteria provider users.
 * Authenticates via the cafeteria_provider guard (separate from the admin users table).
 */
class CafeteriaProviderUser extends Authenticatable
{
    use HasFactory;
    use HasUuidPrimaryKey;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'cafeteria_provider_users';

    protected $fillable = [
        'cafeteria_provider_id',
        'name',
        'email',
        'username',
        'phone_number',
        'password',
        'status',
        'portal_enabled',
        'must_change_password',
        'last_login_at',
        'last_login_ip',
        'email_verified_at',
        'created_by',
        'updated_by',
        'suspended_by',
        'suspended_at',
        'suspension_reason',
        'metadata',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'phone_number',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
            'portal_enabled' => 'boolean',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
            'metadata' => 'array',
        ];
    }

    // ── Auth helpers ──────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPortalEnabled(): bool
    {
        return (bool) $this->portal_enabled;
    }

    /** True when the user can log in to the provider portal. */
    public function canLogin(): bool
    {
        return $this->isActive()
            && $this->isPortalEnabled()
            && $this->cafeteriaProvider !== null
            && (bool) $this->cafeteriaProvider->is_active;
    }

    public function initials(): string
    {
        $words = array_filter(explode(' ', $this->name ?? ''));
        $letters = array_map(fn (string $w) => mb_strtoupper(mb_substr($w, 0, 1)), $words);

        return implode('', array_slice($letters, 0, 2));
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function cafeteriaProvider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePortalEnabled($query)
    {
        return $query->where('portal_enabled', true);
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    protected static function newFactory()
    {
        return \Database\Factories\CafeteriaProviderUserFactory::new();
    }
}
