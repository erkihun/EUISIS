<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service type (cafeteria, transport, ...), published for other modules'
 * relations and lookups by code or id. Writes are refused; the Entitlements
 * module's own models and commands change service types.
 */
final class ServiceTypeRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $table = 'service_types';

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'sort_order' => 'integer',
        ];
    }

    /** The name stored in polymorphic columns, as for the Entitlements module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\ServiceType';
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
