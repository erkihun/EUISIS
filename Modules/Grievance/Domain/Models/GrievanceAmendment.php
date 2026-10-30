<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A controlled change to a submitted grievance (after it was returned for correction).
 */
class GrievanceAmendment extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_amendments';

    protected $fillable = [
        'grievance_id',
        'changes',
        'reason',
        'amended_by',
        'amended_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'amended_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function amender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }
}
