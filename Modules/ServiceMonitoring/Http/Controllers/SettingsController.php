<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Occupation;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\UpdateSettings;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringSettings;
use Modules\ServiceMonitoring\Domain\Models\MonitoringSetting;
use Modules\ServiceMonitoring\Http\Requests\UpdateSettingsRequest;

/** Declaration text, the stamped-copy requirement, the issuing organization and the driver occupations. */
final class SettingsController extends Controller
{
    public function edit(ServiceMonitoringSettings $settings): Response
    {
        $this->authorize('manage', MonitoringSetting::class);
        $row = $settings->row();

        return Inertia::render('ServiceMonitoring/Settings', [
            'settings' => [
                'require_stamped_copy' => (bool) $row->require_stamped_copy,
                'declaration_text_en' => (string) $row->declaration_text_en,
                'declaration_text_am' => (string) $row->declaration_text_am,
                'owner_organization_id' => $row->owner_organization_id,
                'driver_occupation_ids' => $settings->driverOccupationIds(),
            ],
            'occupations' => Occupation::query()->where('is_active', true)->orderBy('name_en')->limit(2000)->get(['id', 'code', 'name_en', 'name_am'])
                ->map(fn (Occupation $o): array => ['id' => $o->id, 'code' => $o->code, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->all(),
            'organizations' => Organization::query()->where('status', 'active')->orderBy('name_en')->limit(2000)->get(['id', 'name_en', 'name_am'])
                ->map(fn (Organization $o): array => ['id' => $o->id, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->all(),
        ]);
    }

    public function update(UpdateSettingsRequest $request, UpdateSettings $update): RedirectResponse
    {
        $data = $request->validated();
        $update->execute([
            'require_stamped_copy' => (bool) $data['require_stamped_copy'],
            'declaration_text_en' => $data['declaration_text_en'],
            'declaration_text_am' => $data['declaration_text_am'],
            'owner_organization_id' => $data['owner_organization_id'] ?? null,
            'driver_occupation_ids' => array_values((array) $data['driver_occupation_ids']),
        ], $request->user());

        return back()->with('success', __('service-monitoring.flash.settings_saved'));
    }
}
