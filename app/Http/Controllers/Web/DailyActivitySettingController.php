<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\DailyActivity\UpdateDailyActivitySettingsRequest;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use App\Support\DailyActivity\DailyActivityAbilities;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily Activities > Settings: the city-wide module rules (deadline,
 * backdating, reminders, evidence) under daily_activity_settings.*.
 *
 * Who reviews whom is organization-scoped operational data and lives on its
 * own page (DailyActivityReviewerController). Nothing here grants a permission.
 */
class DailyActivitySettingController extends Controller
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $canViewSettings = $user->can('daily_activity_settings.view') || $user->can('daily_activity_settings.update');

        // Reviewer assignments used to live here; their managers land on the new page.
        if (! $canViewSettings && $user->can('daily_activities.manage_reviewers')) {
            return to_route('daily-activities.reviewers.index');
        }
        abort_unless($canViewSettings, 403);

        return Inertia::render('DailyActivities/Settings', [
            'fields' => $this->settings->getGroupForAdmin(SystemSettingsRegistry::GROUP_DAILY_ACTIVITY),
            'canUpdate' => $user->can('daily_activity_settings.update'),
            'can' => DailyActivityAbilities::for($user),
        ]);
    }

    public function update(UpdateDailyActivitySettingsRequest $request, UpdateSystemSettingsGroupAction $updateGroup): RedirectResponse
    {
        $validated = $request->validated();
        if (isset($validated['work_week_days'])) {
            $validated['work_week_days'] = array_values(array_unique(array_map('strval', $validated['work_week_days'])));
        }

        // Audited by the shared settings action, like every other group.
        $updateGroup->execute(SystemSettingsRegistry::GROUP_DAILY_ACTIVITY, $validated, $request->user());

        return back()->with('success', __('daily-activities.settings_updated'));
    }
}
