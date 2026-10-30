<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts\ReadModels;

use App\Enums\TransactionStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded service transaction, published for My Portal's activity and
 * the dashboards. Writes are refused: transactions are recorded through
 * ServiceDeliveryRegistry and CardServiceAuthorization.
 */
final class ServiceTransactionRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'service_transactions';

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'occurred_at' => 'datetime',
            'amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /** The name stored in polymorphic columns, as for the Entitlements module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\ServiceTransaction';
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceTypeRecord::class, 'service_type_id');
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServicePointRecord::class, 'service_provider_id');
    }
}
