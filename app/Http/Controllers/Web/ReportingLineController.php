<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\OrganizationRelationshipType;
use App\Enums\RelationshipTargetType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportingLineResource;
use App\Models\InstitutionOffice;
use App\Models\InstitutionOfficeRelationship;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use App\Services\OrganizationRelationships\ReportingLineService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class ReportingLineController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * Reporting lines across institution offices and organization units.
     *
     * The sidebar links here as a page, so the default response is the Inertia
     * screen; the JSON collection stays available to explicit API callers.
     */
    public function index(Request $request)
    {
        $this->authorizeReportingLines($request);

        $sourceType = $this->oneOf($request->query('source_type'), ['institution_office', 'organization_unit']);
        $relationshipType = $this->oneOf($request->query('relationship_type'), $this->secondaryRelationshipTypes());
        $targetType = $this->oneOf($request->query('target_type'), $this->targetTypes());
        $search = trim((string) $request->query('q', '')) ?: null;

        $officeLines = $sourceType === 'organization_unit'
            ? collect()
            : InstitutionOfficeRelationship::query()
                ->active()
                ->secondary()
                ->with('sourceOffice')
                ->when($relationshipType, fn (Builder $query) => $query->where('relationship_type', $relationshipType))
                ->when($targetType, fn (Builder $query) => $query->where('target_type', $targetType))
                ->when($search, fn (Builder $query) => $query->whereHas(
                    'sourceOffice',
                    fn (Builder $office) => $office
                        ->where('name_en', 'like', '%'.$search.'%')
                        ->orWhere('name_am', 'like', '%'.$search.'%')
                        ->orWhere('office_code', 'like', '%'.$search.'%'),
                ))
                ->get();

        $unitLines = $sourceType === 'institution_office'
            ? collect()
            : OrganizationUnitRelationship::query()
                ->active()
                ->secondary()
                ->with('sourceUnit')
                ->when($relationshipType, fn (Builder $query) => $query->where('relationship_type', $relationshipType))
                ->when($targetType, fn (Builder $query) => $query->where('target_type', $targetType))
                ->when($search, fn (Builder $query) => $query->whereHas(
                    'sourceUnit',
                    fn (Builder $unit) => $unit
                        ->where('name_en', 'like', '%'.$search.'%')
                        ->orWhere('name_am', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%'),
                ))
                ->get();

        $lines = $officeLines->concat($unitLines);

        if ($request->expectsJson()) {
            return ReportingLineResource::collection($lines);
        }

        $sorted = $lines
            ->sortBy(fn ($line) => mb_strtolower(
                (string) ($line->sourceOffice?->name_en ?? $line->sourceUnit?->name_en ?? ''),
            ))
            ->values();

        $page = max(1, $request->integer('page') ?: 1);

        // Each row resolves its own target, so only the visible page is turned
        // into resources.
        $paginator = new LengthAwarePaginator(
            ReportingLineResource::collection($sorted->forPage($page, self::PER_PAGE)->values())->resolve($request),
            $sorted->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('ReportingLines/Index', [
            'lines' => [
                'data' => $paginator->items(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            'filters' => [
                'q' => $search,
                'source_type' => $sourceType,
                'relationship_type' => $relationshipType,
                'target_type' => $targetType,
            ],
            'options' => [
                'sourceTypes' => ['institution_office', 'organization_unit'],
                'relationshipTypes' => $this->secondaryRelationshipTypes(),
                'targetTypes' => $this->targetTypes(),
            ],
            'summary' => $this->summary($lines),
        ]);
    }

    public function organization(Request $request, Organization $organization, ReportingLineService $service)
    {
        $this->authorizeReportingLines($request);

        return ReportingLineResource::collection(
            $service->getOfficesReportingToOrganization($organization)
                ->concat($service->getUnitsReportingToOrganization($organization)),
        );
    }

    public function institutionOffice(Request $request, InstitutionOffice $institutionOffice, ReportingLineService $service)
    {
        $this->authorizeReportingLines($request);

        return ReportingLineResource::collection($service->getAllActiveReportingLines($institutionOffice));
    }

    public function organizationUnit(Request $request, OrganizationUnit $organizationUnit, ReportingLineService $service)
    {
        $this->authorizeReportingLines($request);

        return ReportingLineResource::collection($service->getAllActiveReportingLines($organizationUnit));
    }

    private function authorizeReportingLines(Request $request): void
    {
        abort_unless(
            $request->user()?->can('functional-reporting.viewReports') || $request->user()?->can('relationships.viewAny'),
            403,
        );
    }

    /** Structural parents are the hierarchy itself, not a reporting line. */
    private function secondaryRelationshipTypes(): array
    {
        return array_values(array_map(
            fn (OrganizationRelationshipType $type) => $type->value,
            array_filter(
                OrganizationRelationshipType::cases(),
                fn (OrganizationRelationshipType $type) => $type->isSecondary(),
            ),
        ));
    }

    private function targetTypes(): array
    {
        return array_map(fn (RelationshipTargetType $type) => $type->value, RelationshipTargetType::cases());
    }

    /** @param  list<string>  $allowed */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** @return list<array{relationship_type: string, count: int}> */
    private function summary(Collection $lines): array
    {
        return $lines
            ->groupBy(fn ($line) => $line->relationship_type?->value ?? (string) $line->relationship_type)
            ->map(fn (Collection $group, string $type): array => [
                'relationship_type' => $type,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
