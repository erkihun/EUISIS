<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\UnitDirectory;
use App\Backbone\Data\OrganizationUnitData;
use App\Enums\OrganizationUnitStatus;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Str;

/** Reads units from the existing table; a unit is in scope when its organization is. */
final readonly class EloquentUnitDirectory implements UnitDirectory
{
    public function __construct(private OrganizationScopeService $scope) {}

    public function find(string $unitUuid): ?OrganizationUnitData
    {
        if (! Str::isUuid($unitUuid)) {
            return null;
        }
        $unit = OrganizationUnit::query()->whereKey($unitUuid)->first();

        return $unit === null ? null : OrganizationUnitData::fromModel($unit);
    }

    public function findFor(User $viewer, string $unitUuid): ?OrganizationUnitData
    {
        $unit = $this->find($unitUuid);

        return $unit !== null && $this->scope->canAccessOrganization($viewer, $unit->organizationUuid) ? $unit : null;
    }

    public function forOrganization(string $organizationUuid, bool $activeOnly = true): array
    {
        if (! Str::isUuid($organizationUuid)) {
            return [];
        }

        return OrganizationUnit::query()
            ->where('organization_id', $organizationUuid)
            ->when($activeOnly, fn ($query) => $query->where('status', OrganizationUnitStatus::Active->value))
            ->orderBy('sort_order')->orderBy('code')
            ->get()
            ->map(fn (OrganizationUnit $unit): OrganizationUnitData => OrganizationUnitData::fromModel($unit))
            ->values()
            ->all();
    }
}
