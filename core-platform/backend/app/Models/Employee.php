<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One person, one employee record. Before it is active an employee goes
 * through the verified-employee stage (ADR 0008): draft → submitted →
 * verified → active (approved), returned to draft with a reason when
 * something must be corrected. After that the employment statuses apply.
 */
class Employee extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const VERIFIED = 'verified';

    public const ACTIVE = 'active';

    /** Not yet approved: no active placement, no card, not served by other systems. */
    public const PRE_APPROVAL = [self::DRAFT, self::SUBMITTED, self::VERIFIED];

    /** Statuses that end employment: no active placement, no valid card. */
    public const SEPARATED = ['retired', 'terminated', 'deceased', 'transferred'];

    protected $guarded = ['id'];

    /** Personal fields never leave the Core through the API. */
    protected $hidden = ['national_id', 'national_id_hash', 'date_of_birth', 'phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship', 'photo_path', 'signature_path', 'metadata'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date', 'metadata' => 'array', 'notification_preferences' => 'array', 'is_demo' => 'boolean',
            'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'approved_at' => 'datetime', 'verification_checklist' => 'array',
        ];
    }

    /** The full name as the ID card prints it in English (the EUISIS rule: name_en, else the metadata, else the full name). */
    public function fullNameEn(): ?string
    {
        return $this->name_en ?: ($this->metadata['name_en'] ?? $this->full_name);
    }

    /** The full name as the ID card prints it in Amharic. */
    public function fullNameAm(): ?string
    {
        return $this->metadata['name_am'] ?? ($this->full_name ?: $this->name_en);
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

    /** Every stored version of the employee's documents, photo and signature (ADR 0009). */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /** The card the employee carries now: current and active. */
    public function activeIdCard(): HasOne
    {
        return $this->hasOne(IdCard::class)->where('is_current', true)->where('status', IdCard::ACTIVE);
    }

    public function isApproved(): bool
    {
        return ! in_array($this->status, self::PRE_APPROVAL, true);
    }

    public function isSeparated(): bool
    {
        return in_array($this->status, self::SEPARATED, true);
    }
}
