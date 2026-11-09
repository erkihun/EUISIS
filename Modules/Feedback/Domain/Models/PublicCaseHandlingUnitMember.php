<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One term of an employee in a public-case handling unit (ADR 0034), with the
 * signature image registered for it. Ending a term keeps the row.
 */
class PublicCaseHandlingUnitMember extends Model
{
    use HasUuidPrimaryKey;

    public const RESPONSIBILITIES = ['head', 'officer'];

    protected $fillable = [
        'handling_unit_id',
        'employee_id',
        'responsibility',
        'effective_from',
        'effective_to',
        'status',
        'appointment_reference',
        'appointed_by',
        'end_reason',
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

    public function handlingUnit(): BelongsTo
    {
        return $this->belongsTo(PublicCaseHandlingUnit::class, 'handling_unit_id');
    }

    public function isServing(): bool
    {
        $today = now()->startOfDay();

        return $this->status === 'active'
            && ($this->effective_from === null || $this->effective_from->lte($today))
            && ($this->effective_to === null || $this->effective_to->gte($today));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeServingOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();

        return $query->where($query->qualifyColumn('status'), 'active')
            ->whereDate($query->qualifyColumn('effective_from'), '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('effective_to'))->orWhereDate($q->qualifyColumn('effective_to'), '>=', $day));
    }
}
