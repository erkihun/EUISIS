<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CafeteriaLedgerEntryType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeteriaSubsidyLedger extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'cafeteria_subsidy_ledger';

    protected $fillable = [
        'employee_id',
        'cafeteria_transaction_id',
        'public_holiday_id',
        'ledger_date',
        'allocated_for_date',
        'week_start_date',
        'week_end_date',
        'usage_mode',
        'entry_type',
        'amount',
        'balance_after',
        'working_day',
        'description',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'ledger_date'        => 'date',
        'allocated_for_date' => 'date',
        'week_start_date'    => 'date',
        'week_end_date'      => 'date',
        'entry_type'         => CafeteriaLedgerEntryType::class,
        'amount'        => 'decimal:2',
        'balance_after' => 'decimal:2',
        'working_day'   => 'boolean',
        'metadata'      => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CafeteriaTransaction::class, 'cafeteria_transaction_id');
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(PublicHoliday::class, 'public_holiday_id');
    }
}
