<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceReasonCodeType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Configurable reason codes (intake return/rejection, withdrawal, closure, reopen, reassignment).
 */
class GrievanceReasonCode extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_reason_codes';

    protected $fillable = [
        'type',
        'code',
        'name_en',
        'name_am',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => GrievanceReasonCodeType::class,
            'is_active' => 'bool',
            'sort_order' => 'int',
        ];
    }
}
