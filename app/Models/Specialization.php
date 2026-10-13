<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A specialization within a profession (brief §28). */
class Specialization extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['profession_id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }
}
