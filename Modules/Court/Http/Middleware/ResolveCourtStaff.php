<?php

declare(strict_types=1);

namespace Modules\Court\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Court\Domain\Models\CourtStaffMember;
use Symfony\Component\HttpFoundation\Response;

/**
 * The court's staff area (ADR 0020). EUISIS decides who works in the court:
 * Super Admin, or an account whose EUISIS roles give a `court.*` permission.
 * The account's court record (signature, stamp, teams) is made the first time
 * and, for the rest of the request, is the user (`court` guard), so the court's
 * permissions, policies and audit see it; the default guard is restored afterwards.
 * A court record a court admin set inactive stays out.
 */
final class ResolveCourtStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::guard('web')->user();
        if (! $account instanceof User) {
            abort(403, __('court::staff.not_court_staff'));
        }

        $member = CourtStaffMember::query()->find($account->getKey());
        if ($member === null && CourtStaffMember::platformAccess($account)) {
            $member = CourtStaffMember::provision($account);
        }
        if (! $member instanceof CourtStaffMember || ($member->status ?? 'active') !== 'active') {
            abort(403, __('court::staff.not_court_staff'));
        }
        $member->setRelation('account', $account);

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
