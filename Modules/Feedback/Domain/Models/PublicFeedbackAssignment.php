<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Feedback\Domain\Enums\PublicFeedbackHandlerType;

/**
 * One handler period of a public submission (brief §53). A reassignment
 * ends the open row and opens a new one; nothing is overwritten. The handler
 * name is snapshotted so the trail reads the same after a rename.
 */
class PublicFeedbackAssignment extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'submission_id',
        'handler_type',
        'handler_id',
        'handler_name_snapshot',
        'handler_name_am_snapshot',
        'routing_rule_id',
        'assigned_by',
        'reason',
        'assigned_at',
        'ended_at',
        'ended_by',
    ];

    protected function casts(): array
    {
        return [
            'handler_type' => PublicFeedbackHandlerType::class,
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(EmployeeServiceFeedback::class, 'submission_id');
    }

    public function routingRule(): BelongsTo
    {
        return $this->belongsTo(PublicFeedbackRoutingRule::class, 'routing_rule_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function handlerName(string $locale = 'en'): string
    {
        return $locale === 'am' && $this->handler_name_am_snapshot !== null
            ? $this->handler_name_am_snapshot
            : $this->handler_name_snapshot;
    }
}
