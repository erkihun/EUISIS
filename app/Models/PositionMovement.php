<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionMovement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'position_id',
        'organization_id',
        'from_organization_unit_id',
        'to_organization_unit_id',
        'moved_by',
        'reason',
        'moved_at',
    ];

    protected function casts(): array
    {
        return [
            'moved_at' => 'datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function fromOrganizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'from_organization_unit_id');
    }

    public function toOrganizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'to_organization_unit_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
