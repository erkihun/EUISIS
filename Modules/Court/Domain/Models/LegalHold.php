<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class LegalHold extends Model
{
    protected $table = 'court_legal_holds';

    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'placed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function holdable(): MorphTo
    {
        return $this->morphTo();
    }
}
