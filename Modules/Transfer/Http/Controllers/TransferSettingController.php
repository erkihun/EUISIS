<?php

declare(strict_types=1);

namespace Modules\Transfer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Transfer\Application\Actions\UpdateTransferSettingsAction;
use Modules\Transfer\Domain\Models\TransferSetting;
use Modules\Transfer\Http\Requests\UpdateTransferSettingsRequest;

class TransferSettingController extends Controller
{
    public function show(): Response
    {
        $this->authorize('view', TransferSetting::class);

        /*
         * NOT `settings`: HandleInertiaRequests shares a global `settings`
         * prop carrying the whole appearance configuration — sidebar colour,
         * primary colour, logo position, logo URL. A page prop of the same
         * name replaces it, which stripped the branding from this screen and
         * left the sidebar unstyled and the default framework logo showing.
         */
        return Inertia::render('Transfers/Settings', [
            'transferSettings' => TransferSetting::current(),
            // Override approvers are chosen from the real roles, not typed.
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'can' => ['update' => Auth::user()?->can('update', TransferSetting::class) ?? false],
        ]);
    }

    public function update(
        UpdateTransferSettingsRequest $request,
        UpdateTransferSettingsAction $action,
    ): RedirectResponse {
        $this->authorize('update', TransferSetting::class);

        $action->execute($request->validated(), Auth::user());

        return to_route('transfer-settings.show')
            ->with('flash', ['message' => __('transfers.settingsUpdated'), 'type' => 'success']);
    }
}
