<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasUuidPrimaryKey;

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
        'phone',
        'email',
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
            'data_quality_score' => 'float',
            'metadata' => 'array',
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
        $this->attributes['national_id_hash'] = $plain !== null ? hash('sha256', $plain) : null;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? '/storage/'.$this->photo_path : null;
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
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
