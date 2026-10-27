<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CafeteriaProviderBranch extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'cafeteria_provider_id',
        'organization_id',
        'code',
        'name_en',
        'name_am',
        'location',
        'contact_person',
        'phone_number',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CafeteriaProviderAssignment::class, 'cafeteria_provider_branch_id');
    }
}
