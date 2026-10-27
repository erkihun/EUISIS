<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationUnit extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id');
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnitType::class, 'organization_unit_type_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'organization_unit_id');
    }
}
