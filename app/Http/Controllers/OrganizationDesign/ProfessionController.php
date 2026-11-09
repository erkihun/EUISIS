<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\CatalogEntryRequest;
use App\Models\Occupation;
use App\Models\Profession;
use App\Models\Specialization;
use App\Services\OrganizationDesign\OrganizationDesignAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** City-level professions and specializations under occupations (brief §28). */
class ProfessionController extends Controller
{
    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('occupations.viewAny') || $request->user()->can('professions.manage'), 403);
        $search = $request->string('search')->toString();

        $occupations = Occupation::query()
            ->when($request->string('occupation_id')->toString(), fn ($q, $id) => $q->whereKey($id))
            ->when($search, fn ($q) => $q->where(fn ($n) => $n->where('code', ci_like_operator(), "%{$search}%")->orWhere('isco_code', ci_like_operator(), "%{$search}%")->orWhere('name_en', ci_like_operator(), "%{$search}%")->orWhere('name_am', ci_like_operator(), "%{$search}%")))
            ->with(['professions.specializations', 'iscoGroup:id,code,revision,name_en,name_am'])
            ->withCount('professions')
            ->orderBy('isco_code')->orderBy('code')
            ->paginate(25)->withQueryString()
            ->through(fn (Occupation $o) => [
                'id' => $o->id, 'code' => $o->code, 'isco_code' => $o->isco_code, 'name_en' => $o->name_en, 'name_am' => $o->name_am, 'is_active' => $o->is_active,
                'isco_group' => $o->iscoGroup?->only(['id', 'code', 'revision', 'name_en', 'name_am']),
                'professions' => $o->professions->map(fn (Profession $p) => [
                    ...$p->only(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'is_active', 'sort_order']),
                    'specializations' => $p->specializations->map->only(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'is_active', 'sort_order'])->values(),
                ])->values(),
            ]);

        return Inertia::render('OrganizationDesign/Professions/Index', [
            'occupations' => $occupations,
            'filters' => $request->only(['occupation_id', 'search']),
            'occupationOptions' => Occupation::query()->where('is_active', true)->orderBy('isco_code')->limit(2000)->get(['id', 'code', 'isco_code', 'name_en', 'name_am'])->map->only(['id', 'code', 'isco_code', 'name_en', 'name_am']),
            'can' => ['manage' => $request->user()->can('professions.manage')],
        ]);
    }

    public function store(CatalogEntryRequest $request): RedirectResponse
    {
        $profession = Profession::query()->create([...$request->validated(), 'created_by' => $request->user()->getKey(), 'updated_by' => $request->user()->getKey()]);
        $this->audit->record(AuditEventType::ProfessionSaved, $request->user(), $profession, $profession->only(['occupation_id', 'code', 'name_en', 'is_active']));

        return back()->with('flash', ['message' => __('organization-design.profession_saved'), 'type' => 'success']);
    }

    public function update(CatalogEntryRequest $request, Profession $profession): RedirectResponse
    {
        $old = $profession->only(['occupation_id', 'code', 'name_en', 'name_am', 'is_active', 'sort_order']);
        $profession->fill([...$request->validated(), 'updated_by' => $request->user()->getKey()])->save();
        $this->audit->record(AuditEventType::ProfessionSaved, $request->user(), $profession, $profession->only(['occupation_id', 'code', 'name_en', 'name_am', 'is_active', 'sort_order']), $old);

        return back()->with('flash', ['message' => __('organization-design.profession_saved'), 'type' => 'success']);
    }

    public function storeSpecialization(CatalogEntryRequest $request, Profession $profession): RedirectResponse
    {
        $specialization = $profession->specializations()->create($request->validated());
        $this->audit->record(AuditEventType::ProfessionSaved, $request->user(), $specialization, $specialization->only(['profession_id', 'code', 'name_en', 'is_active']));

        return back()->with('flash', ['message' => __('organization-design.specialization_saved'), 'type' => 'success']);
    }

    public function updateSpecialization(CatalogEntryRequest $request, Specialization $specialization): RedirectResponse
    {
        $old = $specialization->only(['code', 'name_en', 'name_am', 'is_active', 'sort_order']);
        $specialization->fill($request->validated())->save();
        $this->audit->record(AuditEventType::ProfessionSaved, $request->user(), $specialization, $specialization->only(['code', 'name_en', 'name_am', 'is_active', 'sort_order']), $old);

        return back()->with('flash', ['message' => __('organization-design.specialization_saved'), 'type' => 'success']);
    }
}
