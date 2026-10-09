<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersOrganizationData;
use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Services\Api\OrganizationDataPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Position catalog reads for approved external applications.
 *
 * Reports establishment and occupancy — whether a seat exists and whether it is
 * filled — without identifying who fills it. Employee detail is a separate
 * scope and a separate endpoint.
 */
class PositionApiController extends Controller
{
    use FiltersOrganizationData;

    public function __construct(private readonly OrganizationDataPresenter $presenter) {}

    /** GET /api/v1/positions */
    public function index(Request $request): JsonResponse
    {
        $query = Position::query()
            ->withCount(['assignments as current_assignments_count' => fn ($assignments) => $assignments
                ->where('is_current', true)]);

        $this->applyCodeFilter($query, $request, 'position_code', 'job_position_code');
        $this->applyUpdatedAfter($query, $request);

        $organizationCode = $request->string('organization_code')->trim()->toString();

        if ($organizationCode !== '') {
            $query->whereHas('organization', fn ($organization) => $organization
                ->where('code', ci_like_operator(), $organizationCode));
        }

        $unitCode = $request->string('organization_unit_code')->trim()->toString();

        if ($unitCode !== '') {
            $query->whereHas('organizationUnit', fn ($unit) => $unit
                ->where('code', ci_like_operator(), $unitCode));
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
    public function show(Position $position): JsonResponse
    {
        $position->loadCount(['assignments as current_assignments_count' => fn ($assignments) => $assignments
            ->where('is_current', true)]);

        return response()->json(['data' => $this->presenter->position($position)]);
    }
}
