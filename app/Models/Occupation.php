<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Occupation extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'isco_code',
        'name_en',
        'name_am',
        'skill_specialization',
        'description',
        'is_active',
        'isco_major_group_code',
        'isco_sub_major_group_code',
        'isco_minor_group_code',
        'isco_unit_group_code',
        'skill_level',
    ];

    public function setIscoCodeAttribute(?string $value): void
    {
        $this->attributes['isco_code'] = $value === null ? null : trim($value);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
