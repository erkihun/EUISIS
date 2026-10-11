<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationDesignStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An institution's service (brief §9–11): what it delivers, justified by
 * one or more functions. Versioned by lineage like a mandate; the version
 * in force is the published one. Distinct from PositionService (what a
 * position is responsible for) and ServiceType (a platform module).
 */
class Service extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'lineage_key', 'version_no', 'code', 'name_en', 'name_am', 'description_en', 'description_am',
        'service_type_id', 'service_classification_id', 'cpc_classification_id', 'beneficiary_type', 'delivery_channel', 'legal_basis',
        'owner_organization_unit_id', 'effective_from', 'effective_to', 'status', 'approved_by', 'approved_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'status' => OrganizationDesignStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(ServiceClassification::class, 'service_classification_id');
    }

    public function cpcClassification(): BelongsTo
    {
        return $this->belongsTo(ServiceClassification::class, 'cpc_classification_id');
    }

    public function ownerUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'owner_organization_unit_id');
    }

    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationFunction::class, 'function_service', 'service_id', 'function_id')->withTimestamps();
    }

    public function isicActivities(): BelongsToMany
    {
        return $this->belongsToMany(IsicActivity::class, 'service_isic_activity', 'service_id', 'isic_activity_id')->withTimestamps();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'lineage_key', 'lineage_key')->orderBy('version_no');
    }
}
