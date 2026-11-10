<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** The last number drawn per institution, direction, language and reset period. Read and bumped under a row lock only. */
class LetterSequence extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'letter_sequences';

    protected $fillable = ['organization_id', 'direction', 'language', 'period_key', 'last_number'];

    protected function casts(): array
    {
        return ['last_number' => 'int'];
    }
}
