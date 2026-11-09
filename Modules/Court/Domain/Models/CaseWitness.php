<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CaseWitness extends Model
{
    protected $table = 'court_case_witnesses';

    protected $guarded = ['id'];

    public function courtCase(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }
}
