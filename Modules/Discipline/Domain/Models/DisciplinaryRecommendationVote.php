<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One member's vote; a dissent keeps its written reason (Art. 8(4)). */
class DisciplinaryRecommendationVote extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCaseMember::class, 'case_member_id');
    }
}
