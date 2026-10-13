<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationDesignStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A position's purpose, reporting line and working conditions, versioned by lineage (brief §61). */
class PositionProfile extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'position_id', 'organization_id', 'lineage_key', 'version_no', 'purpose_en', 'purpose_am', 'reports_to_position_id', 'working_conditions_en', 'working_conditions_am',
        'authorities_en', 'authorities_am', 'relationships_en', 'relationships_am', 'status', 'effective_from', 'effective_to', 'approved_by', 'approved_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'status' => OrganizationDesignStatus::class, 'effective_from' => 'date', 'effective_to' => 'date', 'approved_at' => 'datetime'];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'reports_to_position_id');
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

    public function jobDescriptions(): HasMany
    {
        return $this->hasMany(JobDescription::class, 'position_profile_id');
    }
}
