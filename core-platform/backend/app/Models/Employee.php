<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    /** Statuses that end employment: no active placement, no valid card. */
    public const SEPARATED = ['retired', 'terminated', 'deceased', 'transferred'];

    protected $guarded = ['id'];

    /** Personal fields never leave the Core through the API. */
    protected $hidden = ['national_id', 'national_id_hash', 'date_of_birth', 'phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship', 'photo_path', 'signature_path', 'metadata'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'metadata' => 'array', 'notification_preferences' => 'array', 'is_demo' => 'boolean'];
    }

    public function currentAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'current_assignment_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(IdCard::class);
    }

    public function isSeparated(): bool
    {
        return in_array($this->status, self::SEPARATED, true);
    }
}
