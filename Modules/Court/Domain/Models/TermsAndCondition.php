<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TermsAndCondition extends Model
{
    protected $table = 'court_terms_and_conditions';

    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'body',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TermsAndCondition $term) {
            if (empty($term->slug)) {
                $term->slug = Str::slug($term->title . '-' . Str::random(4));
            }
        });
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(ApplicantTermAcceptance::class, 'terms_and_condition_id');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
