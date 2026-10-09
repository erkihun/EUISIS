<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Security\SessionActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The browser's view of the idle clock.
 *
 * Both endpoints sit behind EnforceSessionIdleTimeout, which has already
 * refused an expired session (401) before either runs. Neither accepts a
 * timestamp from the client: the server clock is the only clock.
 */
class SessionActivityController extends Controller
{
    public function __construct(private readonly SessionActivityService $activity) {}

    /**
     * POST /session/activity — sent by the page only after real interaction
     * (typing, clicking), throttled client-side. The middleware has recorded
     * it as activity; this just reports the new remaining time.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        return $this->state($request);
    }

    /**
     * GET /session/status — passive (listed in security.session.passive_routes),
     * so checking the clock never resets it. Used before showing the warning,
     * so another tab's activity is taken into account.
     */
    public function status(Request $request): JsonResponse
    {
        return $this->state($request);
    }

    private function state(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => true,
            'remaining_seconds' => $this->activity->remainingSeconds($request->session()),
            'idle_timeout_seconds' => $this->activity->idleTimeoutSeconds(),
        ])->header('Cache-Control', 'no-store');
    }
}
