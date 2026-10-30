<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Grievance\Domain\Enums\GrievanceHandlerType;
use Modules\Grievance\Domain\Enums\GrievanceSlaDayType;
use Modules\Grievance\Domain\Enums\GrievanceSlaPurpose;
use Modules\Grievance\Domain\Enums\GrievanceSlaStartPoint;

/**
 * Deadline policy for one handler level/category. Stages snapshot it when created, so later edits never rewrite historical deadlines.
 */
class GrievanceSlaProfile extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_sla_profiles';

    protected $fillable = [
        'name_en',
        'name_am',
        'purpose',
        'organization_id',
        'handler_type',
        'handler_id',
        'category_id',
        'resolution_days',
        'day_type',
        'start_point',
        'warning_thresholds',
        'auto_escalate',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => GrievanceSlaPurpose::class,
            'handler_type' => GrievanceHandlerType::class,
            'day_type' => GrievanceSlaDayType::class,
            'start_point' => GrievanceSlaStartPoint::class,
            'warning_thresholds' => 'array',
            'auto_escalate' => 'bool',
            'is_active' => 'bool',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'resolution_days' => 'int',
            'priority' => 'int',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GrievanceCategory::class, 'category_id');
    }
}
