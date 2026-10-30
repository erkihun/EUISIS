<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An internal case note. Never visible to the complainant.
 */
class GrievanceNote extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_notes';

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'body',
        'visibility',
        'author_user_id',
    ];

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
