<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** What a mandate empowers the organization to do; functions may cite one. */
class OrganizationPower extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'mandate_id', 'code', 'title_en', 'title_am', 'description_en', 'description_am',
        'legal_reference', 'sequence', 'effective_from', 'effective_to', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function mandate(): BelongsTo
    {
        return $this->belongsTo(InstitutionMandate::class, 'mandate_id');
    }

    public function functions(): HasMany
    {
        return $this->hasMany(OrganizationFunction::class, 'power_id');
    }
}
