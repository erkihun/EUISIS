<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Entitlements\GrantEntitlementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntitlementStoreRequest;
use App\Models\CafeteriaProvider;
use App\Models\Employee;
use App\Models\Entitlement;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EntitlementController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Entitlement::class);

        return Inertia::render('Entitlements/Index', [
            'entitlements' => Entitlement::query()
                ->with(['employee.currentAssignment.organization', 'serviceType', 'serviceProvider'])
                ->orderByDesc('created_at')
                ->get(),
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'employee_number', 'full_name']),
            'serviceTypes' => ServiceType::query()->orderBy('name_en')->get(['id', 'name_en']),
            'providers' => ServiceProvider::query()->orderBy('name')->get(['id', 'name', 'service_type_id']),
            'cafeteriaProviders' => CafeteriaProvider::query()
                ->with('organization')
                ->orderBy('name_en')
                ->get(['id', 'code', 'name_en', 'name_am', 'organization_id', 'is_active', 'location']),
        ]);
    }

    public function store(EntitlementStoreRequest $request, GrantEntitlementAction $grantEntitlementAction): RedirectResponse
    {
        $this->authorize('create', Entitlement::class);

        $employee = Employee::query()->findOrFail($request->string('employee_id')->toString());
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
