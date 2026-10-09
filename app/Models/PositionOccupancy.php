<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OccupancyStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionOccupancy extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'position_establishment_id',
        'employee_id',
        'employee_assignment_id',
        'organization_id',
        'position_id',
        'occupied_from',
        'occupied_until',
        'status',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => OccupancyStatus::class,
            'occupied_from' => 'date',
            'occupied_until' => 'date',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(PositionEstablishment::class, 'position_establishment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'employee_assignment_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
