<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\PositionProfileRequest;
use App\Http\Requests\OrganizationDesign\PositionWorkRequest;
use App\Models\Competency;
use App\Models\CompetencyLevel;
use App\Models\EducationLevel;
use App\Models\JobDescription;
use App\Models\Organization;
use App\Models\OrganizationDesignStatusHistory;
use App\Models\Position;
use App\Models\PositionCompetencyRequirement;
use App\Models\PositionProfile;
use App\Models\PositionQualificationRequirement;
use App\Models\PositionWorkLink;
use App\Services\OrganizationDesign\PositionProfileService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The position side of the design: profile versions, work links, requirements, and the list of job descriptions. */
class PositionDesignController extends Controller
{
    public function __construct(private readonly PositionProfileService $profiles, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PositionProfile::class);
        $user = $request->user();
        $query = Position::query()->with(['organization:id,code,name_en,name_am', 'organizationUnit:id,code,name_en,name_am', 'occupation:id,code,isco_code,name_en,name_am'])
            ->addSelect(['*',
                'profile_status' => PositionProfile::query()->select('status')->whereColumn('position_id', 'positions.id')->whereIn('status', ['published', 'draft', 'under_review'])->orderByRaw("case status when 'published' then 0 when 'draft' then 1 else 2 end")->limit(1),
                'job_description_status' => JobDescription::query()->select('status')->whereColumn('position_id', 'positions.id')->orderByDesc('version_no')->limit(1),
            ])
            ->when($request->string('organization_id')->toString(), fn ($q, $id) => $q->where('organization_id', $id))
            ->when($request->string('organization_unit_id')->toString(), fn ($q, $id) => $q->where('organization_unit_id', $id))
            ->when($request->string('search')->toString(), fn ($q, $search) => $q->where(fn ($n) => $n->where('job_position_code', ci_like_operator(), "%{$search}%")->orWhere('title_en', ci_like_operator(), "%{$search}%")->orWhere('title_am', ci_like_operator(), "%{$search}%")))
            ->when($request->string('state')->toString() === 'without_profile', fn ($q) => $q->whereDoesntHave('profiles'))
            ->when($request->string('state')->toString() === 'without_jd', fn ($q) => $q->whereDoesntHave('jobDescriptions'))
            ->where('is_active', true)->orderBy('job_position_code');
        $this->scope->applyOrganizationScope($query, $user);

        return Inertia::render('OrganizationDesign/PositionDesign/Index', [
            'positions' => $query->paginate(25)->withQueryString()->through(fn (Position $p) => [
                'id' => $p->id, 'job_position_code' => $p->job_position_code, 'title_en' => $p->title_en, 'title_am' => $p->title_am, 'grade_level' => $p->grade_level,
                'organization' => $p->organization?->only(['id', 'code', 'name_en', 'name_am']), 'unit' => $p->organizationUnit?->only(['id', 'code', 'name_en', 'name_am']),
                'occupation' => $p->occupation?->only(['id', 'code', 'isco_code', 'name_en', 'name_am']),
                'profile_status' => $p->profile_status, 'job_description_status' => $p->job_description_status,
            ]),
            'filters' => $request->only(['organization_id', 'organization_unit_id', 'search', 'state']),
            'organizations' => $this->organizationOptions($user),
        ]);
    }

    public function show(Request $request, Position $position): Response
    {
        $this->authorize('viewPosition', [PositionProfile::class, $position]);
        $user = $request->user();
        $position->load(['organization:id,code,name_en,name_am', 'organizationUnit:id,code,name_en,name_am', 'occupation:id,code,isco_code,name_en,name_am']);
        $profiles = PositionProfile::query()->where('position_id', $position->getKey())->with(['reportsTo:id,job_position_code,title_en,title_am', 'approver:id,name', 'creator:id,name'])->orderByDesc('version_no')->get();
        $current = $profiles->firstWhere('status', OrganizationDesignStatus::Published) ?? $profiles->first();
        $links = PositionWorkLink::query()->where('position_id', $position->getKey())->orderBy('sequence')->get();
        $objects = [];
        foreach (PositionWorkLink::OBJECT_MODELS as $type => $model) {
            $ids = $links->where('object_type', $type)->pluck('object_id');
            $objects[$type] = $ids->isEmpty() ? collect() : $model::query()->whereIn('id', $ids)->get()->keyBy('id');
        }
        $canDesign = $user->can('designPosition', [PositionProfile::class, $position]);

        return Inertia::render('OrganizationDesign/PositionDesign/Show', [
            'position' => [
                'id' => $position->id, 'job_position_code' => $position->job_position_code, 'title_en' => $position->title_en, 'title_am' => $position->title_am, 'grade_level' => $position->grade_level,
                'organization' => $position->organization?->only(['id', 'code', 'name_en', 'name_am']), 'organization_id' => $position->organization_id,
                'unit' => $position->organizationUnit?->only(['id', 'code', 'name_en', 'name_am']), 'occupation' => $position->occupation?->only(['id', 'code', 'isco_code', 'name_en', 'name_am']),
            ],
            'profile' => $current ? $this->profileRow($current, $user) : null,
            'profiles' => $profiles->map(fn (PositionProfile $p) => $this->profileRow($p, $user))->values(),
            'workLinks' => $links->map(fn (PositionWorkLink $l) => ['id' => $l->id, 'object_type' => $l->object_type, 'object_id' => $l->object_id, 'is_primary' => $l->is_primary, 'object' => $this->objectRow($objects[$l->object_type]->get($l->object_id))])->values(),
            'competencies' => PositionCompetencyRequirement::query()->where('position_id', $position->getKey())->with(['competency:id,code,name_en,name_am', 'level:id,code,name_en,name_am,rank'])->orderBy('sequence')->get()
                ->map(fn ($r) => ['id' => $r->id, 'competency_id' => $r->competency_id, 'minimum_level_id' => $r->minimum_level_id, 'is_mandatory' => $r->is_mandatory, 'source' => $r->source, 'competency' => $r->competency?->only(['id', 'code', 'name_en', 'name_am']), 'level' => $r->level?->only(['id', 'code', 'name_en', 'name_am', 'rank'])])->values(),
            'qualifications' => PositionQualificationRequirement::query()->where('position_id', $position->getKey())->with('educationLevel:id,code,name_en,name_am,rank')->orderBy('sequence')->get()
                ->map(fn ($r) => ['id' => $r->id, 'education_level_id' => $r->education_level_id, 'field_of_study' => $r->field_of_study, 'certification' => $r->certification, 'license' => $r->license, 'experience_years' => $r->experience_years, 'is_mandatory' => $r->is_mandatory, 'source' => $r->source, 'education_level' => $r->educationLevel?->only(['id', 'code', 'name_en', 'name_am', 'rank'])])->values(),
            'jobDescriptions' => JobDescription::query()->where('position_id', $position->getKey())->with(['preparer:id,name', 'publisher:id,name'])->orderByDesc('version_no')->get()
                ->map(fn (JobDescription $jd) => ['id' => $jd->id, 'reference_number' => $jd->reference_number, 'version_no' => $jd->version_no, 'status' => $jd->status->value, 'effective_from' => $jd->effective_from?->toDateString(), 'effective_to' => $jd->effective_to?->toDateString(), 'prepared_by' => $jd->preparer?->name, 'published_by' => $jd->publisher?->name])->values(),
            'history' => OrganizationDesignStatusHistory::query()->where('entity_type', (new PositionProfile)->getMorphClass())->whereIn('entity_id', $profiles->pluck('id'))->with('actor:id,name')->orderByDesc('created_at')->limit(50)->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at->toISOString()]),
            'catalog' => $canDesign ? $this->catalog($position) : null,
            'can' => [
                'design' => $canDesign,
                'approveProfile' => $user->can('position-profiles.approve') && $this->scope->canAccess($user, $position->organization_id),
                'draftJobDescription' => $user->can('draftJobDescription', [JobDescription::class, $position]) && $current?->status === OrganizationDesignStatus::Published
                    && ! JobDescription::query()->where('position_id', $position->getKey())->whereIn('status', ['draft', 'under_review', 'approved'])->exists(),
            ],
        ]);
    }

    public function storeProfile(PositionProfileRequest $request, Position $position): RedirectResponse
    {
        $this->profiles->createDraft($request->user(), $position, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.profile_saved'), 'type' => 'success']);
    }

    public function updateProfile(PositionProfileRequest $request, PositionProfile $profile): RedirectResponse
    {
        $this->profiles->updateDraft($request->user(), $profile, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.profile_saved'), 'type' => 'success']);
    }

    public function storeProfileVersion(PositionProfileRequest $request, PositionProfile $profile): RedirectResponse
    {
        $this->profiles->newVersion($request->user(), $profile, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.profile_version_created'), 'type' => 'success']);
    }

    public function publishProfile(Request $request, PositionProfile $profile): RedirectResponse
    {
        $this->authorize('approve', $profile);
        $this->profiles->publish($request->user(), $profile);

        return back()->with('flash', ['message' => __('organization-design.profile_published'), 'type' => 'success']);
    }

    public function cancelProfile(Request $request, PositionProfile $profile): RedirectResponse
    {
        $this->authorize('update', $profile);
        $this->profiles->cancel($request->user(), $profile, $request->string('reason')->toString() ?: null);

        return back()->with('flash', ['message' => __('organization-design.profile_cancelled'), 'type' => 'success']);
    }

    public function syncWorkLinks(PositionWorkRequest $request, Position $position): RedirectResponse
    {
        $this->profiles->syncWorkLinks($request->user(), $position, $request->validated('items') ?? []);

        return back()->with('flash', ['message' => __('organization-design.work_links_saved'), 'type' => 'success']);
    }

    public function syncRequirements(PositionWorkRequest $request, Position $position): RedirectResponse
    {
        $this->profiles->syncRequirements($request->user(), $position, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.requirements_saved'), 'type' => 'success']);
    }

    public function deriveRequirements(Request $request, Position $position): RedirectResponse
    {
        $this->authorize('designPosition', [PositionProfile::class, $position]);
        $result = $this->profiles->deriveRequirements($request->user(), $position);

        return back()->with('flash', ['message' => __('organization-design.requirements_derived', $result), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function profileRow(PositionProfile $p, $user): array
    {
        return [
            ...$p->only(['id', 'version_no', 'lineage_key', 'purpose_en', 'purpose_am', 'reports_to_position_id', 'working_conditions_en', 'working_conditions_am', 'authorities_en', 'authorities_am', 'relationships_en', 'relationships_am']),
            'status' => $p->status->value, 'effective_from' => $p->effective_from?->toDateString(), 'effective_to' => $p->effective_to?->toDateString(),
            'reports_to' => $p->reportsTo?->only(['id', 'job_position_code', 'title_en', 'title_am']), 'created_by' => $p->creator?->name, 'approved_by' => $p->approver?->name,
            'can' => [
                'update' => $p->status === OrganizationDesignStatus::Draft && $user->can('update', $p),
                'publish' => $p->status === OrganizationDesignStatus::Draft && $user->can('approve', $p) && (int) $p->created_by !== (int) $user->getKey(),
                'version' => $p->status === OrganizationDesignStatus::Published && $user->can('update', $p),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function objectRow($object): ?array
    {
        if ($object === null) {
            return null;
        }
        $name = $object->name_en ?? $object->title_en ?? null;
        $nameAm = $object->name_am ?? $object->title_am ?? null;

        return ['id' => $object->id, 'code' => $object->code, 'name_en' => $name, 'name_am' => $nameAm];
    }

    /** @return array<string, mixed> */
    private function catalog(Position $position): array
    {
        $org = $position->organization_id;

        // Work objects and positions are searched on the server (organization-design.lookups), never shipped whole.
        return [
            'organization_id' => $org,
            'competencies' => Competency::query()->where('is_active', true)->with('framework:id,code')->orderBy('code')->limit(2000)->get()->map(fn (Competency $c) => ['id' => $c->id, 'code' => $c->code, 'name_en' => $c->name_en, 'name_am' => $c->name_am, 'framework_code' => $c->framework?->code])->values(),
            'levels' => CompetencyLevel::query()->where('is_active', true)->orderBy('rank')->get()->map->only(['id', 'code', 'name_en', 'name_am', 'rank'])->values(),
            'educationLevels' => EducationLevel::query()->where('is_active', true)->orderBy('rank')->get()->map->only(['id', 'code', 'name_en', 'name_am', 'rank'])->values(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function organizationOptions($user): array
    {
        $query = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($query, $user, 'id');

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }
}
