<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntitlementStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entitlement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'service_type_id',
        'service_provider_id',
        'status',
        'quota_limit',
        'quota_used',
        'effective_from',
        'effective_to',
        'rule_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntitlementStatus::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'rule_snapshot' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    /**
     * The entitlement a transaction is charged against (F19): active, inside
     * its effective dates on `$date`, and for this provider or provider-neutral
     * — a provider-specific grant wins over a neutral one, the latest start
     * over an older one. Never simply "the first row for the service".
     */
    public static function inForceFor(string $employeeId, string $serviceTypeId, ?string $providerId, ?string $date = null): ?self
    {
        $on = $date ?? now()->toDateString();

        return self::query()
            ->where('employee_id', $employeeId)
            ->where('service_type_id', $serviceTypeId)
            ->where('status', EntitlementStatus::Active->value)
            ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $on))
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->where(fn (Builder $query) => $query->whereNull('service_provider_id')->when($providerId !== null, fn (Builder $inner) => $inner->orWhere('service_provider_id', $providerId)))
            ->orderByRaw('CASE WHEN service_provider_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('effective_from')
            ->first();
    }
}
