<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\UpdateCommunicationSettingsRequest;
use App\Services\Communication\CommunicationMetrics;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** System Settings → Communication, with the module's operational status. */
class CommunicationSettingsController extends Controller
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function index(Request $request, CommunicationMetrics $metrics): Response
    {
        abort_unless($request->user()->can('communication.settings.manage'), 403);

        return Inertia::render('Communication/Settings', [
            'fields' => $this->settings->getGroupForAdmin(SystemSettingsRegistry::GROUP_COMMUNICATION),
            'metrics' => $metrics->snapshot(),
        ]);
    }

    public function update(UpdateCommunicationSettingsRequest $request, UpdateSystemSettingsGroupAction $update): RedirectResponse
    {
        // The shared action audits every changed key (old and new values).
        $update->execute(SystemSettingsRegistry::GROUP_COMMUNICATION, $request->validated(), $request->user());

        return back()->with('success', __('communication.flash.settings_saved'));
    }
}
