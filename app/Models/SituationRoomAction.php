<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A management follow-up recorded in the Situation Room Action Register
 * against one institution. It is the leader's own to-do, not a complaint
 * assignment: nothing here changes who handles a public submission.
 */
class SituationRoomAction extends Model
{
    use HasUuidPrimaryKey;

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    public const CLOSED_STATUSES = ['done', 'cancelled'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    public const SOURCE_TYPES = ['attention', 'organization', 'submission'];

    protected $fillable = [
        'organization_id',
        'source_type',
        'source_reference',
        'title',
        'action_text',
        'owner_user_id',
        'owner_organization_unit_id',
        'due_date',
        'priority',
        'status',
        'created_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ownerUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'owner_organization_unit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SituationRoomActionEvent::class, 'action_id')->orderBy('occurred_at');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }
}
