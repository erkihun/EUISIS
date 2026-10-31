<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Database\Factories\ServiceMonitoring\ActionItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceMonitoring\Domain\Enums\ActionItemStatus;

/** A follow-up action from a monitoring visit (D22). Overdue open items feed the dashboards and reminders. */
class ActionItem extends Model
{
    /** @use HasFactory<ActionItemFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_action_items';

    protected $fillable = [
        'visit_id',
        'finding_id',
        'title',
        'owner_organization_id',
        'owner_user_id',
        'due_on',
        'status',
        'completion_note',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date:Y-m-d',
            'status' => ActionItemStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ActionItemFactory
    {
        return ActionItemFactory::new();
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(MonitoringVisit::class, 'visit_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(VisitFinding::class, 'finding_id');
    }

    public function ownerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owner_organization_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(MonitoringAttachment::class, 'attachable_id')->where('attachable_type', MonitoringAttachment::TYPE_ACTION_ITEM);
    }

    /** Open items whose due date (Addis) has passed. */
    public function scopeOverdue(Builder $query, string $today): Builder
    {
        return $query->whereIn('status', [ActionItemStatus::Open->value, ActionItemStatus::InProgress->value])->where('due_on', '<', $today);
    }

    public function isOverdue(string $today): bool
    {
        return ! $this->status->isClosed() && $this->due_on->toDateString() < $today;
    }
}
