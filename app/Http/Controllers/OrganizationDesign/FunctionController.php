<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\FunctionRequest;
use App\Http\Requests\OrganizationDesign\PowerRequest;
use App\Models\InstitutionMandate;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Services\OrganizationDesign\FunctionService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Powers and the function tree. Writes happen under a mandate; the tree is read per organization. */
class FunctionController extends Controller
{
    public function __construct(private readonly FunctionService $functions, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationFunction::class);
        $user = $request->user();

        $organizations = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($organizations, $user, 'id');
        $options = $organizations->limit(500)->get(['id', 'code', 'name_en', 'name_am']);

        $organizationId = $request->string('organization_id')->toString() ?: ($options->first()?->id);
        abort_if($organizationId !== null && ! $this->scope->canAccess($user, $organizationId), 403);

        return Inertia::render('OrganizationDesign/Functions/Index', [
            'organizations' => $options,
            'organizationId' => $organizationId,
            'mandates' => $organizationId ? InstitutionMandate::query()->where('organization_id', $organizationId)->orderBy('code')->orderByDesc('version_no')
                ->get(['id', 'code', 'version_no', 'title_en', 'title_am', 'status'])
                ->map(fn ($m) => ['id' => $m->id, 'code' => $m->code, 'version_no' => $m->version_no, 'title_en' => $m->title_en, 'title_am' => $m->title_am, 'status' => $m->status->value]) : [],
            'tree' => $organizationId ? $this->functions->tree($organizationId) : [],
            'filters' => $request->only(['organization_id']),
        ]);
    }

    public function storePower(PowerRequest $request, InstitutionMandate $mandate): RedirectResponse
    {
        $this->functions->createPower($request->user(), $mandate, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.power_saved'), 'type' => 'success']);
    }

    public function updatePower(PowerRequest $request, OrganizationPower $power): RedirectResponse
    {
        $this->functions->updatePower($request->user(), $power, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.power_saved'), 'type' => 'success']);
    }

    public function destroyPower(Request $request, OrganizationPower $power): RedirectResponse
    {
        $this->authorize('delete', $power);
        $this->functions->removePower($request->user(), $power);

        return back()->with('flash', ['message' => __('organization-design.power_removed'), 'type' => 'success']);
    }

    public function store(FunctionRequest $request, InstitutionMandate $mandate): RedirectResponse
    {
        $this->functions->createFunction($request->user(), $mandate, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.function_saved'), 'type' => 'success']);
    }

    public function update(FunctionRequest $request, OrganizationFunction $function): RedirectResponse
    {
        $this->functions->updateFunction($request->user(), $function, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.function_saved'), 'type' => 'success']);
    }

    public function move(Request $request, OrganizationFunction $function): RedirectResponse
    {
        $this->authorize('update', $function);
        $data = $request->validate(['parent_function_id' => ['nullable', 'uuid']]);
        $this->functions->moveFunction($request->user(), $function, $data['parent_function_id'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.function_moved'), 'type' => 'success']);
    }

    public function destroy(Request $request, OrganizationFunction $function): RedirectResponse
    {
        $this->authorize('delete', $function);
        $this->functions->removeFunction($request->user(), $function);

        return back()->with('flash', ['message' => __('organization-design.function_removed'), 'type' => 'success']);
    }
}
