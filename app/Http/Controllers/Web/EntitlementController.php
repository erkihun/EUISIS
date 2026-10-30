<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Entitlements\GrantEntitlementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntitlementStoreRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Entitlement;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cafeteria\Contracts\CafeteriaDirectory;
use Modules\Providers\Domain\Models\ServiceProvider;

class EntitlementController extends Controller
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Entitlement::class);

        /** @var User $user */
        $user = $request->user();

        $entitlements = Entitlement::query()
            // Only the fields the list shows; never the full employee record.
            ->with([
                'employee:id,employee_number,full_name,name_en',
                'serviceType:id,name_en',
                'serviceProvider:id,name',
            ])
            ->when(! $this->scope->isUnrestricted($user), fn ($query) => $query->whereIn(
                'employee_id',
                Employee::query()->select('id')->whereIn(
                    'current_assignment_id',
                    EmployeeAssignment::query()->select('id')
                        ->where('is_current', true)
                        ->whereIn('organization_id', $this->scope->allowedOrganizationIds($user)),
                ),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Entitlements/Index', [
            'entitlements' => $entitlements,
            'serviceTypes' => ServiceType::query()->orderBy('name_en')->get(['id', 'name_en']),
            'providers' => ServiceProvider::query()->orderBy('name')->get(['id', 'name', 'service_type_id']),
            'cafeteriaProviders' => app(CafeteriaDirectory::class)->locations(),
        ]);
    }

    public function store(EntitlementStoreRequest $request, GrantEntitlementAction $grantEntitlementAction): RedirectResponse
    {
        $this->authorize('create', Entitlement::class);

        $employee = Employee::query()->findOrFail($request->string('employee_id')->toString());
        abort_unless($this->scope->canAccessEmployee($request->user(), $employee), 403);

        $serviceType = ServiceType::query()->findOrFail($request->string('service_type_id')->toString());
        $provider = $request->string('service_provider_id')->toString() !== ''
            ? ServiceProvider::query()->findOrFail($request->string('service_provider_id')->toString())
            : null;

        $grantEntitlementAction->execute(
            $employee,
            $serviceType,
            $provider,
            $request->user(),
            $request->integer('quota_limit') ?: null,
        );

        return back()->with('success', __('entitlements.granted_successfully'));
    }
}
