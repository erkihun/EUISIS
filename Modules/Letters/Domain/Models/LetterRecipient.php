<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A copy recipient (ግልባጭ) of a letter: an institution in the system, which
 * gets its own registry entry awaiting receipt at dispatch, or an external
 * party named on the letter.
 */
class LetterRecipient extends Model
{
    use HasUuidPrimaryKey;

    public const KIND_CC = 'cc';

    protected $table = 'letter_recipients';

    protected $fillable = ['letter_id', 'kind', 'organization_id', 'external_name', 'external_address', 'position'];

    protected function casts(): array
    {
        return ['position' => 'int'];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
