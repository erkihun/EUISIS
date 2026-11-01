<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\Concerns\HasAmharicText;
use Illuminate\Database\Eloquent\Model;

class HomeResource extends Model
{
    protected $table = 'court_home_resources';

    use HasAmharicText;

    protected $fillable = [
        'sort_order', 'is_active', 'is_featured', 'type',
        'title', 'description', 'file_path', 'external_url',
        'cover_image', 'published_at',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'is_featured'  => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderBy('sort_order');
    }

    public function isDownloadable(): bool
    {
        return in_array($this->type, ['form', 'document']) && $this->file_path;
    }
}
