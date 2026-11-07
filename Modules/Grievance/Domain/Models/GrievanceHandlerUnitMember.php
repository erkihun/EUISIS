<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Grievance\Domain\Enums\GrievanceUnitResponsibility;

/**
 * One term of a named member of a Grievance Team or Directorate (ADR 0029):
 * the responsibility, the dates and the signature captured at appointment.
 * History is kept: a term is ended, never deleted.
 */
class GrievanceHandlerUnitMember extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'handler_unit_id',
        'employee_id',
        'responsibility',
        'effective_from',
        'effective_to',
        'status',
        'appointment_reference',
        'appointed_by',
        'end_reason',
        'end_reason_code',
        'signature_path',
        'signature_sha256',
        'signature_recorded_at',
        'signature_recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'signature_recorded_at' => 'datetime',
        ];
    }

    public function handlerUnit(): BelongsTo
    {
        return $this->belongsTo(GrievanceHandlerUnit::class, 'handler_unit_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function appointedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appointed_by');
    }

    public function responsibilityEnum(): GrievanceUnitResponsibility
    {
        return GrievanceUnitResponsibility::tryFrom((string) $this->responsibility) ?? GrievanceUnitResponsibility::Expert;
    }

    public function signaturePath(): ?string
    {
        return $this->signature_path ?: ($this->employee?->signature_path ?: null);
    }

    public function hasSignature(): bool
    {
        return $this->signaturePath() !== null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->effective_from === null || $this->effective_from->lte(now()))
            && ($this->effective_to === null || $this->effective_to->gte(now()->startOfDay()));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeServingOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();

        return $query->where($query->qualifyColumn('status'), 'active')
            ->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('effective_from'))->orWhereDate($q->qualifyColumn('effective_from'), '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('effective_to'))->orWhereDate($q->qualifyColumn('effective_to'), '>=', $day));
    }
}
