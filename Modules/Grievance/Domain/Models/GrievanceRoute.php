<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Grievance\Domain\Enums\GrievanceHandlerType;
use Modules\Grievance\Domain\Enums\GrievanceMovementType;

/**
 * One edge of the grievance routing graph: where a case goes from a source (an organization entry point or a handler) for a given movement type. Administrative hierarchy is never assumed; every edge is explicit and must be approved before use.
 */
class GrievanceRoute extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_routes';

    protected $fillable = [
        'source_handler_type',
        'source_handler_id',
        'include_descendants',
        'target_handler_type',
        'target_handler_id',
        'movement_type',
        'category_id',
        'sla_profile_id',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'source_handler_type' => GrievanceHandlerType::class,
            'target_handler_type' => GrievanceHandlerType::class,
            'movement_type' => GrievanceMovementType::class,
            'include_descendants' => 'bool',
            'is_active' => 'bool',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'priority' => 'int',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GrievanceCategory::class, 'category_id');
    }

    public function slaProfile(): BelongsTo
    {
        return $this->belongsTo(GrievanceSlaProfile::class, 'sla_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
