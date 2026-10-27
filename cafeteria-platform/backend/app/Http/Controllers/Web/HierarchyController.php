<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageHierarchyVersion;
use App\Http\Controllers\Controller;
use App\Models\CoreAuditLog;
use App\Models\HierarchyVersion;
use App\Models\User;
use App\Services\HierarchyTree;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EUISIS HierarchyVersions/Index, Show and Tree. A city-wide administrator
 * also gets the governance panel on Show (steps, impact, the next action) and
 * the edit links of a draft; HierarchyVersionController carries the changes.
 */
class HierarchyController extends Controller
{
    public function __construct(private readonly HierarchyTree $tree, private readonly ManageHierarchyVersion $versions) {}

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
            'can' => ['create' => ManageHierarchyVersion::governs($request->user())],
        ]);
    }

    public function show(Request $request, HierarchyVersion $hierarchyVersion): Response
    {
        $tree = $this->tree->build($hierarchyVersion, $request->user());
        $editable = $this->editable($request, $hierarchyVersion);

        return Inertia::render('HierarchyVersions/Show', [
            'version' => $this->tree->version($hierarchyVersion),
            'edges' => $this->tree->edges($hierarchyVersion, $request->user()),
            'tree' => $tree,
            'summary' => $this->tree->summary($tree),
            // Publishing is a step of the governance panel, after approval.
            'can' => ['edit' => $editable, 'archive' => false, 'publish' => false, 'manageTree' => $editable, 'createEdge' => $editable],
            'governance' => collect(['manage', 'approve', 'publish'])->contains(fn (string $step): bool => (bool) $request->user()?->can('hierarchy-versions.'.$step))
                ? $this->governance($hierarchyVersion, $request->user()) : null,
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
            'edges' => $this->tree->edges($hierarchyVersion, $request->user()),
            'summary' => $this->tree->summary($tree),
            'filters' => $filters,
            'can' => ['manageTree' => $this->editable($request, $hierarchyVersion), 'createEdge' => $this->editable($request, $hierarchyVersion)],
        ]);
    }

    private function editable(Request $request, HierarchyVersion $version): bool
    {
        return $version->status === 'draft' && ManageHierarchyVersion::governs($request->user());
    }

    /**
     * Where the version is in its lifecycle, what it changes against the structure it was copied from,
     * and what this administrator may do next; the answers are ManageHierarchyVersion's own.
     *
     * @return array<string, mixed>
     */
    private function governance(HierarchyVersion $version, User $user): array
    {
        $names = User::query()->whereIn('id', array_filter([$version->created_by, $version->reviewed_by, $version->approved_by]))->pluck('name', 'id');
        $inForce = in_array($version->status, [HierarchyVersion::PUBLISHED, HierarchyVersion::ARCHIVED], true);
        // A version published before the governed steps (an import or a snapshot) has no publish row: its own dates stand in.
        $publishedAt = CoreAuditLog::query()->where('action', 'hierarchy.publish')->where('subject_id', (string) $version->id)->latest('id')->value('created_at')
            ?? ($inForce ? ($version->approval_date ?? $version->effective_from ?? $version->created_at) : null);
        $approvalRefusal = $version->status === 'review' ? $this->versions->approvalRefusal($version, $user) : null;
        $publicationRefusal = $version->status === 'approved' ? $this->versions->publicationRefusal($version) : null;
        $step = fn (string $key, mixed $by, mixed $at): array => ['key' => $key, 'by' => $by === null ? null : $names->get($by), 'at' => $at === null ? null : Carbon::parse($at)->format('Y-m-d H:i')];

        return [
            'status' => $version->status,
            // What a pending version would change; a version in force or archived has nothing left to weigh.
            'impact' => $inForce ? null : $this->versions->impact($version),
            'steps' => [
                $step('created', $version->created_by, $version->created_at),
                $step('submitted', $version->reviewed_by, $version->reviewed_at),
                $step('approved', $version->approved_by, $version->approval_date),
                $step('published', null, $publishedAt),
            ],
            'approval_blocked' => $approvalRefusal !== null,
            'publication_blocked' => $publicationRefusal === null ? null : ($publicationRefusal[0] === 'effective_from' ? 'effective_date' : 'structure_changed'),
            'can' => [
                'review' => $version->status === 'draft' && $user->can('hierarchy-versions.manage'),
                'approve' => $version->status === 'review' && $approvalRefusal === null && $user->can('hierarchy-versions.approve'),
                'reject' => in_array($version->status, ['review', 'approved'], true) && $user->can('hierarchy-versions.approve'),
                'publish' => $version->status === 'approved' && $publicationRefusal === null && $user->can('hierarchy-versions.publish'),
            ],
            'urls' => collect(ManageHierarchyVersion::TRANSITIONS)->mapWithKeys(fn (string $transition): array => [
                $transition => route('hierarchy-versions.'.$transition, $version),
            ])->all(),
        ];
    }
}
