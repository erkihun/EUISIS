<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceEventVisibility;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unified case timeline entry. `data` carries only safe display parameters, never narrative text.
 */
class GrievanceCaseEvent extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_case_events';

    public $timestamps = false;

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'event',
        'visibility',
        'actor_user_id',
        'data',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => GrievanceEventVisibility::class,
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
