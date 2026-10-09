<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PublicFaq extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'question_en', 'question_am', 'answer_en', 'answer_am', 'category', 'is_published', 'sort_order', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
