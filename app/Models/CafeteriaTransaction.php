<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CafeteriaTransactionStatus;
use App\Enums\CafeteriaUsageMode;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CafeteriaTransaction extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'service_transaction_id',
        'transaction_number',
        'employee_id',
        'id_card_id',
        'cafeteria_provider_id',
        'transaction_date',
        'transaction_time',
        'scanned_at',
        'meal_amount',
        'subsidy_amount_applied',
        'employee_payable_amount',
        'deduction_amount',
        'transaction_type',
        'status',
        'scan_sequence_for_day',
        'is_extra_scan',
        'is_holiday',
        'is_working_day',
        'usage_mode',
        'available_amount_before',
        'week_start_date',
        'week_end_date',
        'available_days_count',
        'consumed_days_count',
        'blocked_reason',
        'qr_reference',
        'scan_nonce',
        'scan_request_hash',
        'fulfilled_at',
        'notes',
        'created_by',
        'metadata',
        'employee_assignment_id',
        'employee_organization_id',
        'cafeteria_service_network_id',
        'provider_id',
        'cafeteria_service_assignment_id',
        'cafeteria_service_policy_id',
        'cafeteria_policy_version',
        'employee_contribution_applied',
        'provider_price_applied',
        'total_amount_applied',
        'currency_code',
        'pricing_source',
        'policy_snapshot',
        'service_terminal_id',
        'cafeteria_settlement_id',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'scanned_at' => 'datetime',
        'meal_amount' => 'decimal:2',
        'subsidy_amount_applied' => 'decimal:2',
        'employee_payable_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'status' => CafeteriaTransactionStatus::class,
        'usage_mode' => CafeteriaUsageMode::class,
        'is_extra_scan' => 'boolean',
        'is_holiday' => 'boolean',
        'is_working_day' => 'boolean',
        'available_amount_before' => 'decimal:2',
        'week_start_date' => 'date',
        'week_end_date' => 'date',
        'available_days_count' => 'integer',
        'consumed_days_count' => 'integer',
        'fulfilled_at' => 'datetime',
        'metadata' => 'array',
        'cafeteria_policy_version' => 'integer',
        'employee_contribution_applied' => 'decimal:2',
        'provider_price_applied' => 'decimal:2',
        'total_amount_applied' => 'decimal:2',
        'policy_snapshot' => 'array',
    ];

    /** The billing owner: the employee's organization on the service date. */
    public function employeeOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'employee_organization_id');
    }

    /** The payee: the provider that delivered the service. */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(CafeteriaServiceNetwork::class, 'cafeteria_service_network_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CafeteriaServicePolicy::class, 'cafeteria_service_policy_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CafeteriaSettlement::class, 'cafeteria_settlement_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function idCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'id_card_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CafeteriaSubsidyLedger::class);
    }

    public function consumedDays(): HasMany
    {
        return $this->hasMany(CafeteriaTransactionConsumedDay::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function foodOrder(): HasOne
    {
        return $this->hasOne(CafeteriaFoodOrder::class, 'fulfilled_transaction_id');
    }

    public function isAccepted(): bool
    {
        return $this->status === CafeteriaTransactionStatus::Accepted;
    }

    public function isReversed(): bool
    {
        return $this->status === CafeteriaTransactionStatus::Reversed;
    }
}
