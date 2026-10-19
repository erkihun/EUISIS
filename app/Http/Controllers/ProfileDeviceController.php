<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Security\UserSessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * The "Devices" tab of profile settings: ending one of the account's own
 * sessions, or every one except the current. Each action only ever touches
 * sessions that belong to the signed-in user (UserSessionRegistry scopes by
 * user id), and the current session is ended through Sign out, never here.
 */
class ProfileDeviceController extends Controller
{
    public function destroy(Request $request, string $session, UserSessionRegistry $registry): RedirectResponse
    {
        // Laravel session ids are 40 alphanumerics; anything else is not a handle we issued.
        abort_unless(preg_match('/^[A-Za-z0-9]{20,64}$/', $session) === 1, 404);

        /** @var User $user */
        $user = $request->user();

        $ended = $registry->revoke($user, $session, $request->session()->getId(), $request);

        return Redirect::route('profile.edit')->with('flash', [
            'message' => __($ended ? 'profile.device_signed_out' : 'profile.device_not_found'),
            'type' => $ended ? 'success' : 'error',
        ]);
    }

    public function destroyOthers(Request $request, UserSessionRegistry $registry): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = $registry->revokeOthers($user, $request->session()->getId(), $request);

        return Redirect::route('profile.edit')->with('flash', [
            'message' => trans_choice('profile.other_devices_signed_out', $count, ['count' => $count]),
            'type' => 'success',
        ]);
    }
}
