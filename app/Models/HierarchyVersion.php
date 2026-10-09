<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HierarchyVersionStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HierarchyVersion extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'version_name',
        'notes',
        'source_document',
        'status',
        'approved_by',
        'approval_date',
        'effective_from',
        'effective_to',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'status' => HierarchyVersionStatus::class,
            'approval_date' => 'datetime',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_demo' => 'bool',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(OrganizationEdge::class);
    }

    public function closurePaths(): HasMany
    {
        return $this->hasMany(OrganizationClosurePath::class);
    }
}
