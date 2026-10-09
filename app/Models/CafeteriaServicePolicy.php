<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CafeteriaExtraScanPolicy;
use App\Enums\CafeteriaPolicyStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * The financial and entitlement terms for one organization's employees at one
 * provider's network or cafeteria, for a date range. Only drafts are edited;
 * everything else changes through a new version.
 */
class CafeteriaServicePolicy extends Model
{
    use HasUuidPrimaryKey;

    public const SCOPE_CAFETERIA = 'cafeteria';

    public const SCOPE_NETWORK = 'network';

    public const SCOPE_PROVIDER = 'provider';

    /** Terms a new version may change; everything else is scope or workflow. */
    public const TERMS = [
        'daily_subsidy_amount', 'employee_contribution_amount', 'provider_price', 'currency_code',
        'max_daily_uses', 'allow_advance_usage', 'advance_max_days', 'extra_scan_policy',
        'monday_enabled', 'tuesday_enabled', 'wednesday_enabled', 'thursday_enabled',
        'friday_enabled', 'saturday_enabled', 'sunday_enabled',
        'exclude_public_holidays', 'block_employee_leave',
    ];

    private const WEEKDAY_COLUMNS = [
        1 => 'monday_enabled', 2 => 'tuesday_enabled', 3 => 'wednesday_enabled', 4 => 'thursday_enabled',
        5 => 'friday_enabled', 6 => 'saturday_enabled', 7 => 'sunday_enabled',
    ];

    protected $fillable = [
        'policy_group_id',
        'version_no',
        'cafeteria_service_assignment_id',
        'organization_id',
        'provider_id',
        'cafeteria_service_network_id',
        'cafeteria_id',
        'scope_key',
        ...self::TERMS,
        'effective_from',
        'effective_to',
        'status',
        'supersedes_policy_id',
        'predecessor_trimmed',
        'predecessor_effective_to',
        'notes',
        'created_by',
        'updated_by',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'activated_at',
        'ended_by',
        'ended_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'daily_subsidy_amount' => 'decimal:2',
            'employee_contribution_amount' => 'decimal:2',
            'provider_price' => 'decimal:2',
            'max_daily_uses' => 'integer',
            'allow_advance_usage' => 'boolean',
            'advance_max_days' => 'integer',
            'extra_scan_policy' => CafeteriaExtraScanPolicy::class,
            'monday_enabled' => 'boolean',
            'tuesday_enabled' => 'boolean',
            'wednesday_enabled' => 'boolean',
            'thursday_enabled' => 'boolean',
            'friday_enabled' => 'boolean',
            'saturday_enabled' => 'boolean',
            'sunday_enabled' => 'boolean',
            'exclude_public_holidays' => 'boolean',
            'block_employee_leave' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'predecessor_trimmed' => 'boolean',
            'predecessor_effective_to' => 'date',
            'status' => CafeteriaPolicyStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'ended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function scopeKeyFor(string $organizationId, string $providerId, ?string $networkId, ?string $cafeteriaId): string
    {
        return implode('|', [$organizationId, $providerId, $networkId ?? '*', $cafeteriaId ?? '*']);
    }

    public function scopeLevel(): string
    {
        return match (true) {
            $this->cafeteria_id !== null => self::SCOPE_CAFETERIA,
            $this->cafeteria_service_network_id !== null => self::SCOPE_NETWORK,
            default => self::SCOPE_PROVIDER,
        };
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CafeteriaServiceAssignment::class, 'cafeteria_service_assignment_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(CafeteriaServiceNetwork::class, 'cafeteria_service_network_id');
    }

    public function cafeteria(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_policy_id');
    }

    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_policy_id');
    }

    /** @param Builder<self> $query */
    public function scopeBindingOn(Builder $query, Carbon $date): Builder
    {
        return $query->whereIn('status', CafeteriaPolicyStatus::binding())
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()));
    }

    public function coversDate(Carbon $date): bool
    {
        $day = $date->copy()->startOfDay();

        return $this->effective_from->lte($day) && ($this->effective_to === null || $this->effective_to->gte($day));
    }

    /** Whether the policy's working-day rule entitles a meal on this weekday. */
    public function isWeekdayEnabled(Carbon $date): bool
    {
        return (bool) $this->getAttribute(self::WEEKDAY_COLUMNS[(int) $date->isoFormat('E')]);
    }

    /** @return list<string> ISO weekday names the policy entitles, e.g. ['monday', ...] */
    public function enabledWeekdays(): array
    {
        return array_values(array_map(
            fn (string $column): string => substr($column, 0, -strlen('_enabled')),
            array_filter(self::WEEKDAY_COLUMNS, fn (string $column): bool => (bool) $this->getAttribute($column)),
        ));
    }

    /**
     * The applied terms a transaction keeps forever. Amounts are strings so
     * the snapshot never passes through a float.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'policy_id' => $this->id,
            'policy_group_id' => $this->policy_group_id,
            'version_no' => $this->version_no,
            'scope_level' => $this->scopeLevel(),
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'daily_subsidy_amount' => (string) $this->daily_subsidy_amount,
            'employee_contribution_amount' => (string) $this->employee_contribution_amount,
            'provider_price' => (string) $this->provider_price,
            'currency_code' => $this->currency_code,
            'max_daily_uses' => $this->max_daily_uses,
            'allow_advance_usage' => $this->allow_advance_usage,
            'advance_max_days' => $this->advance_max_days,
            'extra_scan_policy' => $this->extra_scan_policy?->value,
            'working_days' => $this->enabledWeekdays(),
            'exclude_public_holidays' => $this->exclude_public_holidays,
            'block_employee_leave' => $this->block_employee_leave,
        ];
    }
}
