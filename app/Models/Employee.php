<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CardStatus;
use App\Enums\DisabilityType;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\FeedbackTokenStatus;
use App\Enums\MaritalStatus;
use App\Models\Concerns\HasIntegrationEvents;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Observers\EmployeeCardImpactObserver;
use App\Support\Security\NationalIdHash;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasIntegrationEvents;
    use HasUuidPrimaryKey;

    protected static function booted(): void
    {
        // After the integration-event projector, as before (both run once booting is over).
        static::whenBooted(fn () => static::observe(EmployeeCardImpactObserver::class));
    }

    protected $fillable = [
        'employee_number',
        'current_assignment_id',
        'first_name',
        'middle_name',
        'last_name',
        'full_name',
        'name_en',
        'national_id',
        'national_id_hash',
        'date_of_birth',
        'gender',
        'nationality',
        'employment_type',
        'marital_status',
        'education_level_id',
        'field_of_study',
        'has_disability',
        'disability_type',
        'service_start_date',
        'phone',
        'email',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'preferred_language',
        'notification_preferences',
        'photo_path',
        'signature_path',
        'status',
        'data_quality_score',
        'metadata',
        'is_demo',
    ];

    protected $hidden = [
        'national_id',
        'national_id_hash',
    ];

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'status' => EmployeeStatus::class,
            'employment_type' => EmploymentType::class,
            'marital_status' => MaritalStatus::class,
            'has_disability' => 'bool',
            'disability_type' => DisabilityType::class,
            'service_start_date' => 'date',
            'data_quality_score' => 'float',
            'metadata' => 'array',
            'notification_preferences' => 'array',
            'is_demo' => 'bool',
        ];
    }

    public function getNationalIdAttribute(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return decrypt($value);
        } catch (DecryptException) {
            return (string) $value;
        }
    }

    public function setNationalIdAttribute(mixed $value): void
    {
        $plain = ($value !== null && $value !== '') ? (string) $value : null;
        $this->attributes['national_id'] = $plain !== null ? encrypt($plain) : null;
        $this->attributes['national_id_hash'] = $plain !== null ? NationalIdHash::of($plain) : null;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if ($this->photo_path && str_starts_with($this->photo_path, 'employee-photos/')) {
            return route('employees.private-photo', $this, false);
        }

        return $this->photo_path ? '/storage/'.$this->photo_path : null;
    }

    /**
     * The full name as the ID card prints it in English: the English name
     * column, then the imported English name, then the primary name.
     */
    public function fullNameEn(): ?string
    {
        return $this->name_en ?: ($this->metadata['name_en'] ?? $this->full_name);
    }

    /** The full name as the ID card prints it in Amharic. */
    public function fullNameAm(): ?string
    {
        return $this->metadata['name_am'] ?? ($this->full_name ?: $this->name_en);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function currentAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'current_assignment_id');
    }

    public function cardRequests(): HasMany
    {
        return $this->hasMany(CardRequest::class);
    }

    public function activeIdCard(): HasOne
    {
        return $this->hasOne(IdCard::class)
            ->where('status', CardStatus::Active)
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()->toDateString());
            })
            ->orderByDesc('activated_at')
            ->orderByDesc('created_at');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function employeeDuplicateFlags(): HasMany
    {
        return $this->hasMany(EmployeeDuplicateFlag::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(EmployeeTransfer::class);
    }

    public function transferApplications(): HasMany
    {
        return $this->hasMany(TransferApplication::class);
    }

    public function feedbackTokens(): HasMany
    {
        return $this->hasMany(EmployeeFeedbackToken::class);
    }

    /** The token currently printed on this employee's feedback QR, if any. */
    public function activeFeedbackToken(): HasOne
    {
        // Ordered, not latestOfMany(): that compares keys with MAX(id), and
        // PostgreSQL has no MAX for uuid columns.
        return $this->hasOne(EmployeeFeedbackToken::class)
            ->where('status', FeedbackTokenStatus::Active->value)
            ->latest('created_at')
            ->latest('id');
    }

    public function serviceFeedback(): HasMany
    {
        return $this->hasMany(EmployeeServiceFeedback::class);
    }

    public function currentOrganization(): HasManyThrough
    {
        return $this->hasManyThrough(
            Organization::class,
            EmployeeAssignment::class,
            'employee_id',
            'id',
            'id',
            'organization_id',
        );
    }
}
