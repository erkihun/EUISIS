<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRelationshipType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationEdge extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'hierarchy_version_id',
        'parent_organization_id',
        'child_organization_id',
        'relationship_type',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'relationship_type' => OrganizationRelationshipType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function hierarchyVersion(): BelongsTo
    {
        return $this->belongsTo(HierarchyVersion::class);
    }

    public function parentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_organization_id');
    }

    public function childOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'child_organization_id');
    }
}
