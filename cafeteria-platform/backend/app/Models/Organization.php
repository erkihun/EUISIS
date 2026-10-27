<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUuids, SoftDeletes;

    public const ACTIVE = 'active';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'metadata' => 'array', 'is_demo' => 'boolean'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(OrganizationType::class, 'organization_type_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }
}
