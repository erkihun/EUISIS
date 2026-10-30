<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service point (the `service_providers` table: where a service is
 * delivered and settled, the partner API's provider), published for other
 * modules' relations, pickers and lookups by code. Writes are refused.
 */
final class ServicePointRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'service_providers';

    protected function casts(): array
    {
        return [
            'is_demo' => 'bool',
        ];
    }

    /** The name stored in polymorphic columns, as for the Entitlements module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\ServiceProvider';
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceTypeRecord::class, 'service_type_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
