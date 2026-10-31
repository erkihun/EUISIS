<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationDesignStatus;
use App\Enums\ProcessType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service's process (brief §17–19): the master design of how the
 * institution delivers it, as a tree of activities and their tasks. One
 * row per version; the published version is in force and immutable, and
 * so is everything under it.
 */
class Process extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'service_id', 'lineage_key', 'version_no', 'code', 'name_en', 'name_am', 'description_en', 'description_am',
        'process_type', 'owner_organization_unit_id', 'effective_from', 'effective_to', 'status', 'approved_by', 'approved_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'process_type' => ProcessType::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'status' => OrganizationDesignStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function ownerUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'owner_organization_unit_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProcessActivity::class)->orderBy('path');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProcessTask::class);
    }

    public function ioRows(): MorphMany
    {
        return $this->morphMany(WorkItemIo::class, 'subject')->orderBy('sequence');
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

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
