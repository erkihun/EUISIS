<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\EntitlementStoreRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cafeteria\Contracts\CafeteriaDirectory;
use Modules\Entitlements\Contracts\ReadModels\EntitlementRecord;
use Modules\Entitlements\Contracts\ReadModels\ServicePointRecord;
use Modules\Entitlements\Contracts\ReadModels\ServiceTypeRecord;
use Modules\Entitlements\Contracts\ServiceDeliveryRegistry;

class EntitlementController extends Controller
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EntitlementRecord::class);

        /** @var User $user */
        $user = $request->user();

        $entitlements = EntitlementRecord::query()
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
            'serviceTypes' => ServiceTypeRecord::query()->orderBy('name_en')->get(['id', 'name_en']),
            'providers' => ServicePointRecord::query()->orderBy('name')->get(['id', 'name', 'service_type_id']),
            'cafeteriaProviders' => app(CafeteriaDirectory::class)->locations(),
        ]);
    }

    public function store(EntitlementStoreRequest $request, ServiceDeliveryRegistry $serviceDelivery): RedirectResponse
    {
        $this->authorize('create', EntitlementRecord::class);

        $employee = Employee::query()->findOrFail($request->string('employee_id')->toString());
        abort_unless($this->scope->canAccessEmployee($request->user(), $employee), 403);

        $serviceDelivery->grantEntitlement(
            (string) $employee->getKey(),
            $request->string('service_type_id')->toString(),
            $request->string('service_provider_id')->toString() ?: null,
            $request->user()->id,
            $request->integer('quota_limit') ?: null,
        );

        return back()->with('success', __('entitlements.granted_successfully'));
    }
}
