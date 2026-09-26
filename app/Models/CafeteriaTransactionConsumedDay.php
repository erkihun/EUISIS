<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeteriaTransactionConsumedDay extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'cafeteria_transaction_id',
        'employee_id',
        'consumed_date',
        'subsidy_amount',
        'is_working_day',
        'source',
        'reversed_at',
        'reversed_by',
        'reversal_transaction_id',
        'organization_id',
        'entitlement_type',
        'slot_no',
        'cafeteria_service_network_id',
        'consumed_at_cafeteria_id',
        'provider_id',
        'usage_type',
        'status',
        'active_key',
    ];

    public const ENTITLEMENT_MEAL = 'meal';

    /**
     * The uniqueness boundary of one entitlement: employee + date + type +
     * slot. It deliberately has no cafeteria in it, so no branch can consume
     * an entitlement another branch already used.
     */
    public static function activeKey(string $employeeId, string $date, string $type = self::ENTITLEMENT_MEAL, int $slot = 1): string
    {
        return "{$employeeId}|{$date}|{$type}|{$slot}";
    }

    protected $casts = [
        'slot_no' => 'integer',
        'consumed_date' => 'date',
        'subsidy_amount' => 'decimal:2',
        'is_working_day' => 'boolean',
        'reversed_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CafeteriaTransaction::class, 'cafeteria_transaction_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
