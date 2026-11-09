<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentStatus;
use App\Http\Controllers\Api\V1\Concerns\FiltersOrganizationData;
use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Services\Api\OrganizationDataPresenter;
use App\Services\Integration\IntegrationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Position catalog reads for approved external applications.
 *
 * Reports establishment and occupancy — whether a seat exists and whether it is
 * filled — without identifying who fills it. Employee detail is a separate
 * scope and a separate endpoint. Positions are bounded to the caller's
 * organizations (F2).
 */
class PositionApiController extends Controller
{
    use FiltersOrganizationData;

    public function __construct(
        private readonly OrganizationDataPresenter $presenter,
        private readonly IntegrationAccessService $access,
    ) {}

    /** GET /api/v1/positions */
    public function index(Request $request): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'positions.read');

        $query = Position::query()
            ->withCount(['assignments as current_assignments_count' => fn ($assignments) => $assignments
                ->where('is_current', true)->where('assignment_status', AssignmentStatus::Active->value)]);

        $this->access->scopeQuery($query, $request);
        $this->applyCodeFilter($query, $request, 'position_code', 'job_position_code');
        $this->applyUpdatedAfter($query, $request);

        $organizationCode = $request->string('organization_code')->trim()->toString();

        if ($organizationCode !== '') {
            $query->whereHas('organization', fn ($organization) => $this->whereCodeEquals($organization, 'code', $organizationCode));
        }

        $unitCode = $request->string('organization_unit_code')->trim()->toString();

        if ($unitCode !== '') {
            $query->whereHas('organizationUnit', fn ($unit) => $this->whereCodeEquals($unit, 'code', $unitCode));
        }

        $status = $request->string('status')->trim()->lower()->toString();

        if ($status === 'active' || $status === 'inactive') {
            $query->where('is_active', $status === 'active');
        }

        $positions = $query->orderBy('title_en')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $positions,
            $positions->getCollection()->map(fn (Position $position): array => $this->presenter->position($position))->all(),
        ));
    }

    /** GET /api/v1/positions/{position} */
    public function show(Request $request, Position $position): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'positions.read');
        $this->access->assertOrganization($request, (string) $position->organization_id);

        $position->loadCount(['assignments as current_assignments_count' => fn ($assignments) => $assignments
            ->where('is_current', true)->where('assignment_status', AssignmentStatus::Active->value)]);

        return response()->json(['data' => $this->presenter->position($position)]);
    }
}
