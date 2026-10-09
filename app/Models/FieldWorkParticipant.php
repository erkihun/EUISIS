<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldWorkParticipantRole;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person on a field work request, with their own placement snapshot and
 * their own check-in / check-out. Written only by FieldWorkService.
 */
class FieldWorkParticipant extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'role' => FieldWorkParticipantRole::class,
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FieldWorkRequest::class, 'field_work_request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function locationEvents(): HasMany
    {
        return $this->hasMany(FieldWorkLocationEvent::class)->orderBy('captured_at');
    }
}
