<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\CatalogEntryRequest;
use App\Models\Competency;
use App\Models\CompetencyFramework;
use App\Models\CompetencyLevel;
use App\Models\CompetencyType;
use App\Models\EducationLevel;
use App\Services\OrganizationDesign\OrganizationDesignAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The competency catalog (brief §30–34): types, named levels, the
 * competencies of each EPMS framework with their type, and the education
 * ladder. City-level; stewards write, designers cite.
 */
class CompetencyCatalogController extends Controller
{
    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('competency-catalog.viewAny') || $request->user()->can('competency-catalog.manage'), 403);

        return Inertia::render('OrganizationDesign/CompetencyCatalog/Index', [
            'types' => CompetencyType::query()->withCount('competencies')->orderBy('sort_order')->orderBy('code')->get()
                ->map(fn (CompetencyType $t) => [...$t->only(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'is_active', 'sort_order']), 'competencies_count' => $t->competencies_count]),
            'levels' => CompetencyLevel::query()->orderBy('rank')->get()->map->only(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'rank', 'is_active']),
            'educationLevels' => EducationLevel::query()->orderBy('rank')->get()->map->only(['id', 'code', 'name_en', 'name_am', 'rank', 'is_active']),
            'frameworks' => CompetencyFramework::query()->with(['competencies' => fn ($q) => $q->orderBy('sort_order')->orderBy('code')])->orderBy('code')->get()
                ->map(fn (CompetencyFramework $f) => [
                    ...$f->only(['id', 'code', 'name_en', 'name_am', 'is_active']),
                    'competencies' => $f->competencies->map->only(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'competency_type_id', 'is_active', 'sort_order'])->values(),
                ]),
            'can' => ['manage' => $request->user()->can('competency-catalog.manage')],
        ]);
    }

    public function storeType(CatalogEntryRequest $request): RedirectResponse
    {
        $type = CompetencyType::query()->create($request->validated());
        $this->audit->record(AuditEventType::CompetencyCatalogSaved, $request->user(), $type, $type->only(['code', 'name_en', 'is_active']));

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }

    public function updateType(CatalogEntryRequest $request, CompetencyType $type): RedirectResponse
    {
        $old = $type->only(['code', 'name_en', 'name_am', 'is_active', 'sort_order']);
        $type->fill($request->validated())->save();
        $this->audit->record(AuditEventType::CompetencyCatalogSaved, $request->user(), $type, $type->only(['code', 'name_en', 'name_am', 'is_active', 'sort_order']), $old);

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }

    public function storeLevel(CatalogEntryRequest $request): RedirectResponse
    {
        $level = CompetencyLevel::query()->create($request->validated());
        $this->audit->record(AuditEventType::CompetencyCatalogSaved, $request->user(), $level, $level->only(['code', 'name_en', 'rank', 'is_active']));

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }

    public function updateLevel(CatalogEntryRequest $request, CompetencyLevel $level): RedirectResponse
    {
        $old = $level->only(['code', 'name_en', 'name_am', 'rank', 'is_active']);
        $level->fill($request->validated())->save();
        $this->audit->record(AuditEventType::CompetencyCatalogSaved, $request->user(), $level, $level->only(['code', 'name_en', 'name_am', 'rank', 'is_active']), $old);

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }

    public function storeEducationLevel(CatalogEntryRequest $request): RedirectResponse
    {
        $level = EducationLevel::query()->create($request->validated());
        $this->audit->record(AuditEventType::CompetencyCatalogSaved, $request->user(), $level, $level->only(['code', 'name_en', 'rank', 'is_active']));

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }

    public function updateEducationLevel(CatalogEntryRequest $request, EducationLevel $educationLevel): RedirectResponse
    {
        $old = $educationLevel->only(['code', 'name_en', 'name_am', 'rank', 'is_active']);
        $educationLevel->fill($request->validated())->save();
        $this->audit->record(AuditEventType::CompetencyCatalogSaved, $request->user(), $educationLevel, $educationLevel->only(['code', 'name_en', 'name_am', 'rank', 'is_active']), $old);

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }

    /** A competency of an EPMS framework: its type and names. Ratings already recorded against it are untouched. */
    public function storeCompetency(Request $request, CompetencyFramework $framework): RedirectResponse
    {
        abort_unless($request->user()->can('competency-catalog.manage'), 403);
        $data = $request->validate($this->competencyRules($framework));
        $competency = $framework->competencies()->create($data);
        $this->audit->record(AuditEventType::CompetencyCatalogSaved, $request->user(), $competency, $competency->only(['framework_id', 'code', 'name_en', 'competency_type_id', 'is_active']));

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }

    public function updateCompetency(Request $request, Competency $competency): RedirectResponse
    {
        abort_unless($request->user()->can('competency-catalog.manage'), 403);
        $data = $request->validate($this->competencyRules($competency->framework, $competency));
        $old = $competency->only(['code', 'name_en', 'name_am', 'competency_type_id', 'is_active', 'sort_order']);
        $competency->fill($data)->save();
        $this->audit->record(AuditEventType::CompetencyCatalogSaved, $request->user(), $competency, $competency->only(['code', 'name_en', 'name_am', 'competency_type_id', 'is_active', 'sort_order']), $old);

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }

    /** @return array<string, list<mixed>> */
    private function competencyRules(CompetencyFramework $framework, ?Competency $competency = null): array
    {
        $required = $competency ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:50', Rule::unique('competencies', 'code')->where('framework_id', $framework->getKey())->ignore($competency)],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'competency_type_id' => ['nullable', 'uuid', Rule::exists('competency_types', 'id')->where('is_active', true)],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
