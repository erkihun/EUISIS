<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupStatusService;
use App\Services\Dashboard\DashboardDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardDataService $dashboardDataService): Response
    {
        abort_unless(
            $request->user() !== null && $dashboardDataService->canViewDashboard($request->user()),
            403,
        );

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
