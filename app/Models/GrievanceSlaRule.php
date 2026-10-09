<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GrievanceOriginLevel;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrievanceSlaRule extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'organization_id',
        'origin_level',
        'escalation_from_type',
        'escalation_to_type',
        'working_days_limit',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'origin_level' => GrievanceOriginLevel::class,
            'working_days_limit' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
