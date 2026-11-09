<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\PositionDirectory;
use App\Backbone\Data\PositionData;
use App\Backbone\Data\PositionOccupancyData;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Positions\PositionCapacityService;
use Illuminate\Support\Str;

/** Reads positions from the existing table; occupancy is PositionCapacityService's rule, not a second one. */
final readonly class EloquentPositionDirectory implements PositionDirectory
{
    public function __construct(
        private OrganizationScopeService $scope,
        private PositionCapacityService $capacity,
    ) {}

    public function find(string $positionUuid): ?PositionData
    {
        $position = $this->model($positionUuid);

        return $position === null ? null : PositionData::fromModel($position);
    }

    public function findFor(User $viewer, string $positionUuid): ?PositionData
    {
        $position = $this->find($positionUuid);

        return $position !== null && $this->scope->canAccessOrganization($viewer, $position->organizationUuid) ? $position : null;
    }

    public function occupancy(string $positionUuid): ?PositionOccupancyData
    {
        $position = $this->model($positionUuid);
        if ($position === null) {
            return null;
        }

        return new PositionOccupancyData(
            positionUuid: (string) $position->getKey(),
            capacity: $this->capacity->capacity((string) $position->getKey()),
            occupied: $this->capacity->currentOccupants((string) $position->getKey()),
            isActive: (bool) $position->is_active,
        );
    }

    public function hasVacancy(string $positionUuid): bool
    {
        return $this->occupancy($positionUuid)?->hasVacancy() ?? false;
    }

    private function model(string $positionUuid): ?Position
    {
        return Str::isUuid($positionUuid) ? Position::query()->whereKey($positionUuid)->first() : null;
    }
}
