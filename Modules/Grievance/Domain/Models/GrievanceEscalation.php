<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrievanceEscalation extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'grievance_id',
        'from_level',
        'to_level',
        'reason',
        'notes',
        'escalated_by_user_id',
        'escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'escalated_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function escalatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by_user_id');
    }
}
