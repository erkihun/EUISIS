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
        'level',
        'due_at',
        'assigned_by',
        'reason',
        'assigned_at',
        'ended_at',
        'ended_by',
        'escalated',
    ];

    protected function casts(): array
    {
        return [
            'handler_type' => PublicFeedbackHandlerType::class,
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
            'level' => 'integer',
            'due_at' => 'datetime',
            'escalated' => 'boolean',
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

    /** Open, with a due date that has passed. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->ended_at === null && $this->due_at !== null && $this->due_at->isPast();
    }

    public function handlerName(string $locale = 'en'): string
    {
        return $locale === 'am' && $this->handler_name_am_snapshot !== null
            ? $this->handler_name_am_snapshot
            : $this->handler_name_snapshot;
    }
}
