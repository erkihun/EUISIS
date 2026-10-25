<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CoreSettings;
use App\Settings\CoreSettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Core settings: the public values for every page (the sign-in page reads
 * them before anyone signs in), and the full list with labels for the
 * administrators who change them.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly CoreSettings $settings) {}

    /** Branding, language, appearance and the maintenance banner: nothing secret. */
    public function public(): JsonResponse
    {
        return response()->json(['settings' => $this->settings->values(publicOnly: true)]);
    }

    /** Every group with its fields, labels, options and current values. */
    public function index(): JsonResponse
    {
        $values = $this->settings->values();
        $groups = [];
        foreach (CoreSettingsRegistry::definitions() as $group => $fields) {
            $groups[] = [
                'key' => $group,
                'fields' => array_map(
                    fn (string $key, array $definition): array => [
                        'key' => $key,
                        'type' => $definition['type'],
                        'label_en' => $definition['label_en'],
                        'label_am' => $definition['label_am'],
                        'description_en' => $definition['description_en'],
                        'description_am' => $definition['description_am'],
                        'options' => $definition['options'],
                        'required' => in_array('required', $definition['rules'], true),
                        'is_public' => $definition['is_public'],
                        'default' => $definition['default'],
                        'value' => $values[$group][$key],
                    ],
                    array_keys($fields),
                    $fields,
                ),
            ];
        }

        return response()->json(['groups' => $groups]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        $values = $this->settings->update($group, (array) $request->input('values', []), $request->user());

        return response()->json(['values' => $values]);
    }
}
