<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class EmploymentStatusHistory extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'status',
        'effective_from',
        'effective_to',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }
}
