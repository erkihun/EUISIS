<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceCustodyAction;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chain-of-custody entry for a piece of evidence.
 */
class GrievanceEvidenceCustody extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_evidence_custody';

    public $timestamps = false;

    protected $fillable = [
        'evidence_id',
        'action',
        'actor_user_id',
        'ip_address',
        'notes',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => GrievanceCustodyAction::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(GrievanceEvidence::class, 'evidence_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
