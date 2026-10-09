<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRelationshipType;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationUnitRelationship extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'source_unit_id',
        'target_type',
        'target_id',
        'relationship_type',
        'is_primary',
        'effective_from',
        'effective_to',
        'status',
        'notes_en',
        'notes_am',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => RelationshipTargetType::class,
            'relationship_type' => OrganizationRelationshipType::class,
            'is_primary' => 'bool',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'status' => RelationshipStatus::class,
        ];
    }

    public function sourceUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'source_unit_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function target(): ?Model
    {
        $targetType = $this->target_type instanceof RelationshipTargetType
            ? $this->target_type
            : RelationshipTargetType::tryFrom((string) $this->target_type);

        return match ($targetType) {
            RelationshipTargetType::Organization => Organization::query()->find($this->target_id),
            RelationshipTargetType::InstitutionOffice => InstitutionOffice::query()->find($this->target_id),
            RelationshipTargetType::OrganizationUnit => OrganizationUnit::query()->find($this->target_id),
            default => null,
        };
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RelationshipStatus::Active->value);
    }

    public function scopeStructuralParent(Builder $query): Builder
    {
        return $query->where('relationship_type', OrganizationRelationshipType::StructuralParent->value);
    }

    public function scopeSecondary(Builder $query): Builder
    {
        return $query->where('relationship_type', '!=', OrganizationRelationshipType::StructuralParent->value);
    }
}
