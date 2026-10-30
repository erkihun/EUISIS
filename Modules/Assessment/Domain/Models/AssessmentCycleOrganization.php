<?php

declare(strict_types=1);

namespace Modules\Assessment\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Organization;

class AssessmentCycleOrganization extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['included_at' => 'datetime', 'excluded_at' => 'datetime', 'last_reminded_at' => 'datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AssessmentCycle::class, 'assessment_cycle_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
