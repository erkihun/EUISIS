<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Court\Domain\Models\Concerns\HasAmharicText;

/** A member of the court (president, judges, registrar) as the public site lists its composition. */
class CourtMember extends Model
{
    protected $table = 'court_members';

    use HasAmharicText;

    protected $fillable = ['sort_order', 'is_active', 'name', 'role', 'bio', 'photo_path'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
