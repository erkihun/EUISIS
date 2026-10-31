<?php

declare(strict_types=1);

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Court\Domain\Models\CourtStaffMember;
use Symfony\Component\HttpFoundation\Response;

/**
 * The court's staff area (ADR 0020): the signed-in platform account works here
 * only with an active `court_staff` row. For the rest of the request that row
 * is the user (`court` guard), so the court's roles, permissions, policies and
 * audit see the court record; the default guard is restored afterwards.
 */
final class ResolveCourtStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = Auth::guard('web')->id();
        $member = $accountId === null ? null : CourtStaffMember::query()->whereKey($accountId)->first();

        if (! $member instanceof CourtStaffMember || ($member->status ?? 'active') !== 'active') {
            abort(403, __('court::app.not_court_staff'));
        }

        $previous = Auth::getDefaultDriver();
        Auth::guard('court')->setUser($member);
        Auth::shouldUse('court');

        try {
            return $next($request);
        } finally {
            Auth::shouldUse($previous);
        }
    }
}
