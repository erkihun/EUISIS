<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Transport\Application\Services\TransportSettings;

class TransportSettingsController extends Controller
{
    public function index(TransportSettings $settings): Response
    {
        /*
         * NOT `settings`: that name is taken by the globally shared appearance
         * configuration, and a page prop of the same name replaces it — the
         * screen then renders with no sidebar colour and the default logo.
         */
        return Inertia::render('Transport/Settings/Index', [
            'transportSettings' => $settings->all(),
            'choices' => TransportSettings::CHOICES,
        ]);
    }

    public function update(Request $request, TransportSettings $settings, WriteAuditLogAction $audit): RedirectResponse
    {
        $rules = [];
        foreach (TransportSettings::DEFAULTS as $key => $default) {
            // The page posts every key; a partial update (a script, a test) may post a subset.
            $rules[$key] = match (TransportSettings::typeOf($key)) {
                'boolean' => ['sometimes', 'required', 'boolean'],
                'integer' => ['sometimes', 'required', 'integer', 'min:0', 'max:43200'],
                'select' => ['sometimes', 'required', Rule::in(TransportSettings::CHOICES[$key])],
                default => ['sometimes', 'required', 'string', 'max:80'],
            };
        }
        $validated = $request->validate($rules);
        $before = $settings->all();

        DB::transaction(function () use ($validated, $request): void {
            foreach ($validated as $key => $value) {
                $type = TransportSettings::typeOf($key);
                $stored = TransportSettings::normalise($key, $value, TransportSettings::DEFAULTS[$key]);

                SystemSetting::query()->updateOrCreate(
                    ['group' => 'transport', 'key' => $key],
                    [
                        'value' => is_bool($stored) ? ($stored ? '1' : '0') : (string) $stored,
                        'type' => $type === 'select' ? 'string' : $type,
                        'label_en' => trans("transport.settings.{$key}", [], 'en'),
                        'label_am' => trans("transport.settings.{$key}", [], 'am'),
                        'default_value' => is_bool(TransportSettings::DEFAULTS[$key]) ? (TransportSettings::DEFAULTS[$key] ? '1' : '0') : (string) TransportSettings::DEFAULTS[$key],
                        'options' => TransportSettings::CHOICES[$key] ?? null,
                        'is_public' => false,
                        'is_encrypted' => false,
                        'is_system' => false,
                        'is_required' => true,
                        'updated_by' => $request->user()?->id,
                    ],
                );
            }
        });

        Cache::forget('system_settings_all');
        Cache::forget('system_settings_public');
        $settings->forget();

        $after = $settings->all();
        $changed = array_filter($after, fn ($value, string $key): bool => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            $audit->execute(AuditEventType::TransportSettingsUpdated, $request->user(), null, null,
                oldValues: array_intersect_key($before, $changed), newValues: $changed, request: $request);
        }

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.settings_updated')]);
    }
}
