<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkItemIoKind;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One typed input / output / beneficiary / dependency row of a process, activity or task (brief §22). */
class WorkItemIo extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['subject_type', 'subject_id', 'organization_id', 'kind', 'label_en', 'label_am', 'description', 'depends_on_activity_id', 'sequence'];

    protected function casts(): array
    {
        return ['kind' => WorkItemIoKind::class, 'sequence' => 'integer'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function dependsOnActivity(): BelongsTo
    {
        return $this->belongsTo(ProcessActivity::class, 'depends_on_activity_id');
    }
}
