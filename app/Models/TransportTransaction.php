<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportTransaction extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'provider_id',
        'employee_id',
        'id_card_id',
        'transport_pass_id',
        'transport_route_id',
        'transport_trip_id',
        'scanned_at',
        'transaction_date',
        'status',
        'result_code',
        'rejection_reason',
        'scan_nonce',
        'qr_reference_hash',
        'scanned_by_provider_user_id',
        'metadata',
    ];

    protected $hidden = [
        'qr_reference_hash',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'transaction_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function idCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class);
    }

    public function pass(): BelongsTo
    {
        return $this->belongsTo(TransportPass::class, 'transport_pass_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'transport_trip_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(ProviderUser::class, 'scanned_by_provider_user_id');
    }
}
