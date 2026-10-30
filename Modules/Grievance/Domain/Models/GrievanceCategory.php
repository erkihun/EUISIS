<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrievanceCategory extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'description_en',
        'description_am',
        'is_active',
        'default_confidentiality',
        'default_priority',
        'requires_executive_approval',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'requires_executive_approval' => 'bool',
            'sort_order' => 'int',
        ];
    }

    public function grievances(): HasMany
    {
        return $this->hasMany(Grievance::class, 'category_id');
    }
}
