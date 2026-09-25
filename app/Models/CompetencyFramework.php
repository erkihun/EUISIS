<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetencyFramework extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'competency_frameworks';

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function competencies(): HasMany
    {
        return $this->hasMany(Competency::class, 'framework_id')->orderBy('sort_order');
    }
}
