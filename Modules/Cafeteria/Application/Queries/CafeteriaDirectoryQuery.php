<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Queries;

use Modules\Cafeteria\Contracts\CafeteriaDirectory;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;

final class CafeteriaDirectoryQuery implements CafeteriaDirectory
{
    public function locations(): array
    {
        return CafeteriaProvider::query()
            ->with('organization:id,name_en')
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_am', 'organization_id', 'is_active', 'location'])
            ->map(fn (CafeteriaProvider $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'name_en' => $c->name_en,
                'name_am' => $c->name_am,
                'organization_id' => $c->organization_id,
                'is_active' => (bool) $c->is_active,
                'location' => $c->location,
                'organization' => $c->organization === null ? null : ['id' => $c->organization->id, 'name_en' => $c->organization->name_en],
            ])
            ->values()
            ->all();
    }
}
