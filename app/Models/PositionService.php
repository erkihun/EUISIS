<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service a position delivers to the public.
 *
 * Has NO relationship to ServiceType. That model is the entitlements catalog
 * (Cafeteria, Health, Transport) describing what an employee may receive; this
 * one describes work an officer performs for a client. The two are separate
 * domains that happen to share the word "service".
 *
 * Owned by an organization and a position: "Employee Record Correction",
 * delivered by the HR Officer post at a particular bureau.
 */
class PositionService extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'position_id',
        'service_no',
        'name_en',
        'name_am',
        'description',
        'is_active',
        'is_performance_evaluation_enabled',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'is_performance_evaluation_enabled' => 'bool',
            'sort_order' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(EmployeeServiceFeedback::class, 'position_service_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Services one position currently offers on the public feedback form. */
    public function scopeForPosition(Builder $query, ?string $positionId): Builder
    {
        /*
         * A null position must match nothing rather than everything: an
         * unassigned employee has no services to be rated on. Expressed with
         * whereRaw rather than comparing to an empty string, which Postgres
         * rejects outright when the column is a uuid.
         */
        if ($positionId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('position_id', $positionId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('service_no');
    }

    /**
     * Restrict to services owned by the given organizations.
     *
     * @param  array<int, string>  $organizationIds
     */
    public function scopeForOrganizations(Builder $query, array $organizationIds): Builder
    {
        return $query->whereIn('organization_id', $organizationIds);
    }

    /** Display label for pickers and reports: "HR-001 — Record Correction". */
    public function label(string $locale = 'en'): string
    {
        $name = $locale === 'am' && $this->name_am !== null && $this->name_am !== ''
            ? $this->name_am
            : $this->name_en;

        return $this->service_no === null || $this->service_no === ''
            ? $name
            : $this->service_no.' — '.$name;
    }
}
