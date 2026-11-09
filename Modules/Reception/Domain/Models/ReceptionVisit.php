<?php

declare(strict_types=1);

namespace Modules\Reception\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ReceptionVisit extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['reference', 'visitor_id', 'organization_id', 'organization_unit_id', 'service_id', 'reception_desk_id', 'appointment_id', 'reception_officer_id', 'purpose', 'status', 'outcome', 'outcome_notes', 'checked_in_at', 'service_started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'service_started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(ReceptionVisitor::class, 'visitor_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function desk(): BelongsTo
    {
        return $this->belongsTo(ReceptionDesk::class, 'reception_desk_id');
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reception_officer_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(ReceptionAppointment::class, 'appointment_id');
    }

    public function queueTicket(): HasOne
    {
        return $this->hasOne(ReceptionQueueTicket::class, 'visit_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(ReceptionReferral::class, 'visit_id')->oldest('referred_at');
    }
}
