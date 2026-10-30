<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\ReadModels;

use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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

    /** Providers of one type, by its code (CAFETERIA, TRANSPORT). */
    public function scopeOfType(Builder $query, string $typeCode): void
    {
        $query->whereIn('provider_type_id', DB::table('provider_types')->where('code', $typeCode)->select('id'));
    }

    public function hasService(string $serviceCode): bool
    {
        return app(ProviderDirectory::class)->hasService((string) $this->getKey(), $serviceCode);
    }
}
