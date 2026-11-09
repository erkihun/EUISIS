<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Services\Core\CoreDashboardMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** GET /core: the Core workspace's home. */
class CoreDashboardController extends Controller
{
    public function __invoke(Request $request, CoreDashboardMetrics $metrics): Response
    {
        return Inertia::render('Core/Dashboard', [
            'metrics' => $metrics->for($request->user()),
            // The latest master-data changes from the audit log (only for audit readers).
            'recent' => $metrics->recentActivity($request->user()),
        ]);
    }
}
