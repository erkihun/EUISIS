<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\SessionActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Background polls (the notification bell, the messages badge, dashboard
 * refreshes, the configured passive routes) share the user's session. When
 * one lands between a form's redirect and the page it leads to, it is the
 * request that "uses up" the flashed toast or validation errors, and the
 * page then shows nothing. Re-flashing on such requests keeps that data for
 * the next real page. Passivity is decided by SessionActivityService, the
 * same rule that keeps these requests from extending the idle session.
 */
class PreserveFlashOnPassiveRequests
{
    public function __construct(private readonly SessionActivityService $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession() && ! $this->activity->isMeaningful($request)) {
            $request->session()->reflash();
        }

        return $response;
    }
}
