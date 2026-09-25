<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AuditEventType;
use App\Http\Requests\Performance\UpdatePerformanceSettingsRequest;
use App\Models\PerformanceRatingBand;
use App\Models\PerformanceRatingScale;
use App\Services\Performance\EpmsAudit;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** System Settings → Performance Management, and the configurable rating scales. */
class PerformanceSettingController extends PerformanceController
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('performance_settings.view'), 403);

        return Inertia::render('Performance/Settings', [
            'fields' => $this->settings->getGroupForAdmin(SystemSettingsRegistry::GROUP_PERFORMANCE),
            'scales' => PerformanceRatingScale::query()->with('bands')->orderBy('scale_type')->get()->map(fn ($s) => [
                'id' => $s->getKey(), 'code' => $s->code, 'name_en' => $s->name_en, 'name_am' => $s->name_am, 'type' => $s->scale_type, 'is_default' => $s->is_default,
                'bands' => $s->bands->map->only(['id', 'min_score', 'max_score', 'level_value', 'label_en', 'label_am'])->all(),
            ])->all(),
            'can' => ['update' => $user->can('performance_settings.update')],
        ]);
    }

    public function update(UpdatePerformanceSettingsRequest $request, UpdateSystemSettingsGroupAction $updateGroup): RedirectResponse
    {
        // Audited by the shared settings action, like every other group.
        $updateGroup->execute(SystemSettingsRegistry::GROUP_PERFORMANCE, $request->validated(), $request->user());

        return back()->with('flash', ['message' => __('performance.settings_updated'), 'type' => 'success']);
    }

    /** Edit band labels/limits of a rating scale (policy data; audited with before/after). */
    public function updateBand(Request $request, string $band): RedirectResponse
    {
        abort_unless($request->user()->can('performance_settings.update'), 403);
        $data = $request->validate([
            'min_score' => ['nullable', 'numeric', 'min:0', 'max:200'], 'max_score' => ['nullable', 'numeric', 'min:0', 'max:200', 'gte:min_score'],
            'label_en' => ['required', 'string', 'max:100'], 'label_am' => ['nullable', 'string', 'max:100'],
        ]);
        $model = PerformanceRatingBand::query()->findOrFail($band);
        $old = $model->only(array_keys($data));
        $model->fill($data)->save();
        app(EpmsAudit::class)->record(AuditEventType::SettingUpdated, $request->user(), $model, $data, $old);

        return $this->saved();
    }
}
