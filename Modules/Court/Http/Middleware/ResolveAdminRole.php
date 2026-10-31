<?php

declare(strict_types=1);

namespace Modules\Court\Http\Middleware;

use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAdminRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $roles = $user->roles()
            ->orderByRaw("CASE WHEN LOWER(court_roles.name) = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('court_roles.name')
            ->get();

        if ($roles->isEmpty()) {
            $request->session()->forget(User::ACTIVE_ROLE_SESSION_KEY);
            $user->setRelation('roles', $roles);

            return $next($request);
        }

        $selectedRoleId = (int) $request->session()->get(User::ACTIVE_ROLE_SESSION_KEY, 0);
        $activeRole = $roles->firstWhere('id', $selectedRoleId);

        if (! $activeRole instanceof Role) {
            $activeRole = $roles->first();
            $request->session()->put(User::ACTIVE_ROLE_SESSION_KEY, $activeRole->id);
        }

        $user->setRelation('roles', $roles);

        return $next($request);
    }
}
