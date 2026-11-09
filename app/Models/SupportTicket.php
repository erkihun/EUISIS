<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One support ticket (docs/assistant/support-helpdesk.md): a signed-in
 * user's request for help with the page they were on, their own description,
 * a user-chosen category and priority, a safe diagnostic context and the
 * team the category routes to. `context` only ever holds allow-listed,
 * redacted strings (SupportTicketService::sanitizeContext).
 */
class SupportTicket extends Model
{
    use HasUuidPrimaryKey;

    public const PERMISSION_VIEW_ALL = 'support-tickets.view-all';

    public const PERMISSION_MANAGE = 'support-tickets.manage';

    public const CATEGORIES = ['access', 'error', 'data', 'how_to', 'performance', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public const ACTIVE_STATUSES = ['open', 'in_progress'];

    protected $fillable = [
        'reference', 'reporter_user_id', 'organization_id', 'module', 'route_name', 'path', 'category', 'priority', 'title', 'description',
        'context', 'error_reference', 'incident_id', 'conversation_id', 'status', 'assigned_team', 'assigned_user_id', 'resolved_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id')->orderBy('occurred_at')->orderBy('created_at');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IntelligenceIncident::class, 'incident_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
