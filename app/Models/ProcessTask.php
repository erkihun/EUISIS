<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A master task of an activity (brief §21): the unit of work the
 * organization designs standards for, and the thing a position task may
 * be traced to. Daily Work records against position tasks, never here.
 */
class ProcessTask extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'activity_id', 'process_id', 'organization_id', 'code', 'name_en', 'name_am', 'description_en', 'description_am',
        'sequence', 'required_output', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_active' => 'boolean'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProcessActivity::class, 'activity_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function ioRows(): MorphMany
    {
        return $this->morphMany(WorkItemIo::class, 'subject')->orderBy('sequence');
    }

    public function standards(): HasMany
    {
        return $this->hasMany(PositionServiceTaskStandard::class, 'master_task_id')->orderByDesc('version_no');
    }

    /** Position tasks traced to this master task. */
    public function positionTasks(): HasMany
    {
        return $this->hasMany(PositionServiceTask::class, 'master_task_id')->orderBy('code');
    }
}
