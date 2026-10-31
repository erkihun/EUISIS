<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\ReadModels;

use App\Models\Organization;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Entitlements\Contracts\ServiceDeliveryRegistry;
use Modules\Providers\Contracts\ProviderDirectory;

/**
 * A provider record, published for other modules' relations and reads
 * (route->provider, settlement->provider). It serializes as the Providers
 * module's own model does and keeps its stored name; writes are refused.
 * Archived providers are excluded unless asked for (withTrashed).
 */
final class ProviderRecord extends ReadOnlyModel
{
    use SoftDeletes;

    protected $table = 'providers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = [
        'phone_number',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** The name stored in polymorphic columns, as for the Providers module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\Provider';
    }

    public function assignedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'assigned_organization_id');
    }

    /** Providers of one type, by its code (CAFETERIA, TRANSPORT). */
    public function scopeOfType(Builder $query, string $typeCode): void
    {
        $query->whereIn('provider_type_id', DB::table('provider_types')->where('code', $typeCode)->select('id'));
    }

    /** Providers that carry the service, in any status of it (as ProviderDirectory::offering). */
    public function scopeOffering(Builder $query, string $serviceCode): void
    {
        $typeId = app(ServiceDeliveryRegistry::class)->serviceTypeId($serviceCode);
        if ($typeId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('id', DB::table('provider_services')->where('service_type_id', $typeId)->select('provider_id'));
    }

    public function hasService(string $serviceCode): bool
    {
        return app(ProviderDirectory::class)->hasService((string) $this->getKey(), $serviceCode);
    }
}
