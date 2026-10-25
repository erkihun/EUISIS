<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\HierarchyVersion;
use App\Services\HierarchyTree;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** EUISIS HierarchyVersions/Index, Show and Tree, read only (versions are prepared in EUISIS). */
class HierarchyController extends Controller
{
    public function __construct(private readonly HierarchyTree $tree) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'effective_from' => $request->string('effective_from')->toString() ?: null,
        ];
        $versions = HierarchyVersion::query()
            ->when($filters['search'], fn ($q, string $search) => $q->where(fn ($inner) => $inner->where('version_name', 'like', "%{$search}%")
                ->orWhere('source_document', 'like', "%{$search}%")->orWhere('notes', 'like', "%{$search}%")))
            ->when($filters['status'], fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['effective_from'], fn ($q, string $from) => $q->whereDate('effective_from', '>=', $from))
            ->latest('created_at')->paginate(12)->withQueryString();

        // The shape of a JSON resource collection: data, links, meta.
        return Inertia::render('HierarchyVersions/Index', [
            'versions' => [
                'data' => $versions->getCollection()->map(fn (HierarchyVersion $version): array => $this->tree->version($version))->values(),
                'links' => ['first' => $versions->url(1), 'last' => $versions->url($versions->lastPage()), 'prev' => $versions->previousPageUrl(), 'next' => $versions->nextPageUrl()],
                'meta' => [
                    'current_page' => $versions->currentPage(), 'from' => $versions->firstItem(), 'last_page' => $versions->lastPage(),
                    'links' => $versions->linkCollection()->toArray(), 'path' => $versions->path(), 'per_page' => $versions->perPage(),
                    'to' => $versions->lastItem(), 'total' => $versions->total(),
                ],
            ],
            'filters' => $filters,
            'can' => ['create' => false],
        ]);
    }

    public function show(Request $request, HierarchyVersion $hierarchyVersion): Response
    {
        $tree = $this->tree->build($hierarchyVersion, $request->user());

        return Inertia::render('HierarchyVersions/Show', [
            'version' => $this->tree->version($hierarchyVersion),
            'edges' => $this->tree->edges($hierarchyVersion),
            'tree' => $tree,
            'summary' => $this->tree->summary($tree),
            'can' => ['edit' => false, 'archive' => false, 'publish' => false, 'manageTree' => false, 'createEdge' => false],
        ]);
    }

    public function tree(Request $request, HierarchyVersion $hierarchyVersion): Response
    {
        $filters = [
            'include_units' => filter_var($request->query('include_units', 'true'), FILTER_VALIDATE_BOOLEAN),
            'include_inactive' => filter_var($request->query('include_inactive', 'false'), FILTER_VALIDATE_BOOLEAN),
        ];
        $tree = $this->tree->build($hierarchyVersion, $request->user(), $filters['include_units'], $filters['include_inactive']);

        return Inertia::render('HierarchyVersions/Tree', [
            'version' => $this->tree->version($hierarchyVersion),
            'tree' => $tree,
            'edges' => $this->tree->edges($hierarchyVersion),
            'summary' => $this->tree->summary($tree),
            'filters' => $filters,
            'can' => ['manageTree' => false, 'createEdge' => false],
        ]);
    }
}
