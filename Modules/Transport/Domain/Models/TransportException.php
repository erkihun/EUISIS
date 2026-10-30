<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Providers\Domain\Models\Provider;
use Modules\Transport\Domain\Enums\TransportExceptionStatus;
use Modules\Transport\Domain\Enums\TransportExceptionType;

class TransportException extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'provider_id', 'transport_trip_id', 'transport_transaction_id', 'employee_id', 'transport_vehicle_id', 'transport_device_id',
        'type', 'severity', 'status', 'detail', 'occurred_at', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransportExceptionType::class,
            'status' => TransportExceptionStatus::class,
            'detail' => 'array',
            'occurred_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'transport_trip_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(TransportTransaction::class, 'transport_transaction_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'transport_vehicle_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(TransportDevice::class, 'transport_device_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
