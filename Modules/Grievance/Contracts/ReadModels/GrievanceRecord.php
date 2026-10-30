<?php

declare(strict_types=1);

namespace Modules\Grievance\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\Organization;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Grievance\Contracts\Data\GrievanceStatus;

/**
 * A grievance case, published for other modules' relations (a tribunal case's
 * grievance) and for recognising one by its stored name. Writes are refused.
 */
final class GrievanceRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'grievances';

    protected function casts(): array
    {
        return [
            'status' => GrievanceStatus::class,
            'incident_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** The name stored in polymorphic columns, as for the Grievance module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\Grievance';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GrievanceCategoryRecord::class, 'category_id');
    }
}
