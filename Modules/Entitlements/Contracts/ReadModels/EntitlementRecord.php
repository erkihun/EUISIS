<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Entitlements\Contracts\Data\EntitlementStatus;

/**
 * An employee's entitlement to a service, published for the employee's
 * relation, My Portal and the dashboards. Writes are refused: entitlements
 * change through the Entitlements module's commands.
 */
final class EntitlementRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'entitlements';

    protected function casts(): array
    {
        return [
            'status' => EntitlementStatus::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'rule_snapshot' => 'array',
        ];
    }

    /** The name stored in polymorphic columns, as for the Entitlements module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\Entitlement';
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
