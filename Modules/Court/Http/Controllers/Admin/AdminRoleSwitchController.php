<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Http\Requests\Admin\SwitchAdminRoleRequest;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class AdminRoleSwitchController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(SwitchAdminRoleRequest $request): RedirectResponse
    {
        $role = $request->user()
            ->roles()
            ->findOrFail($request->validated('role_id'));

        $request->session()->regenerate();
        $request->session()->put(User::ACTIVE_ROLE_SESSION_KEY, $role->id);

        return redirect()
            ->route('court.admin.dashboard')
            ->with('success', __('court::app.role_switched', [
                'role' => Str::headline($role->name),
            ]));
    }
}
