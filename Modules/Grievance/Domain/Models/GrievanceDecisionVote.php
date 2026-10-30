<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceVoteType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A panel member's vote (and optional dissenting opinion) on a decision draft.
 */
class GrievanceDecisionVote extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_decision_votes';

    protected $fillable = [
        'decision_id',
        'employee_id',
        'user_id',
        'vote',
        'opinion',
        'voted_at',
    ];

    protected function casts(): array
    {
        return [
            'vote' => GrievanceVoteType::class,
            'voted_at' => 'datetime',
        ];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(GrievanceDecision::class, 'decision_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
