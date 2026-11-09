<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A profession under an occupation (brief §28). City-level catalog; deactivated, never deleted, once required. */
class Profession extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['occupation_id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'is_active', 'sort_order', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function specializations(): HasMany
    {
        return $this->hasMany(Specialization::class)->orderBy('sort_order')->orderBy('code');
    }
}
