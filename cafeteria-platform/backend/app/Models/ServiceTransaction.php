<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTransaction extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'id_card_id',
        'service_type_id',
        'service_provider_id',
        'entitlement_id',
        'status',
        'occurred_at',
        'reference',
        'amount',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'occurred_at' => 'datetime',
            'amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
