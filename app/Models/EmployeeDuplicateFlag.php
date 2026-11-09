<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDuplicateFlag extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'matched_employee_id',
        'risk_score',
        'matched_fields',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'risk_score' => 'float',
            'matched_fields' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function matchedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'matched_employee_id');
    }
}
