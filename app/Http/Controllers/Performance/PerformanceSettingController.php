<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AuditEventType;
use App\Http\Requests\Performance\UpdatePerformanceSettingsRequest;
use App\Models\PerformanceRatingBand;
use App\Models\PerformanceRatingScale;
use App\Services\Performance\Calculation\Dec;
use App\Services\Performance\EpmsAudit;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            'min_score' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:200'],
            'max_score' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:200'],
            'label_en' => ['required', 'string', 'max:100'], 'label_am' => ['nullable', 'string', 'max:100'],
        ], [], trans('performance.band_attributes'));
        $model = PerformanceRatingBand::query()->with('scale')->findOrFail($band);

        DB::transaction(function () use ($model, $data, $request): void {
            // Serialize edits within a scale so two valid requests cannot create an overlap together.
            PerformanceRatingScale::query()->whereKey($model->scale_id)->lockForUpdate()->firstOrFail();
            $model->refresh();
            if ($model->level_value !== null) {
                // A level band (competency 1..N) has no score range: only its labels change.
                unset($data['min_score'], $data['max_score']);
            } else {
                // A score maps to the first band that contains it, so bands of one scale must not overlap.
                $range = fn ($min, $max): array => [Dec::of($min), Dec::of($max)];
                [$min, $max] = $range(
                    array_key_exists('min_score', $data) ? $data['min_score'] : $model->min_score,
                    array_key_exists('max_score', $data) ? $data['max_score'] : $model->max_score,
                );
                if ($min !== null && $max !== null && $min->isGreaterThan($max)) {
                    throw ValidationException::withMessages(['max_score' => __('performance.errors.band_range_invalid')]);
                }
                $siblings = PerformanceRatingBand::query()->where('scale_id', $model->scale_id)->whereKeyNot($model->getKey())->whereNull('level_value')->get();
                foreach ($siblings as $other) {
                    [$otherMin, $otherMax] = $range($other->min_score, $other->max_score);
                    $startsBeforeOtherEnds = $min === null || $otherMax === null || $min->isLessThanOrEqualTo($otherMax);
                    $otherStartsBeforeEnd = $otherMin === null || $max === null || $otherMin->isLessThanOrEqualTo($max);
                    if ($startsBeforeOtherEnds && $otherStartsBeforeEnd) {
                        throw ValidationException::withMessages(['min_score' => __('performance.errors.band_overlap', [
                            'label' => app()->getLocale() === 'am' && $other->label_am ? $other->label_am : $other->label_en,
                        ])]);
                    }
                }
            }

            $old = $model->only(array_keys($data));
            $model->fill($data)->save();
            app(EpmsAudit::class)->record(AuditEventType::SettingUpdated, $request->user(), $model, $data, $old);
        });

        return $this->saved();
    }
}
