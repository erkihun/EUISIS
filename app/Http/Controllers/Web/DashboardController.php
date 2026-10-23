<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupStatusService;
use App\Services\Dashboard\DashboardDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardDataService $dashboardDataService): Response|RedirectResponse
    {
        abort_if($request->user() === null, 403);

        // Roles without dashboard access sign in to My Portal; links that still
        // point here (an old bookmark, the error page's home button) take them
        // there too instead of to a 403 that offers this same link again.
        if (! $dashboardDataService->canViewDashboard($request->user())) {
            return redirect()->route('employee.portal');
        }

        $filters = $request->validate([
            'date_range' => ['nullable', 'in:today,7d,30d,90d,custom'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'organization_id' => ['nullable', 'uuid'],
            'provider_id' => ['nullable', 'uuid'],
            'service_type_id' => ['nullable', 'uuid'],
            'card_status' => ['nullable', 'string'],
            'transfer_status' => ['nullable', 'string'],
        ]);

        return Inertia::render('Dashboard/Index', [
            ...$dashboardDataService->build($request->user(), $filters),
            'backupHealth' => $request->user()->can('backups.view_status')
                ? app(BackupStatusService::class)->summary() : null,
        ]);
    }
}
