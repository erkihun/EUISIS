<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /core/settings: the settings Core owns, gathered on one page. Each entry
 * opens the existing settings screen (the System Settings tab, code rules, card
 * templates, API management); service-module settings are not listed.
 */
class CoreSettingsController extends Controller
{
    /** @var list<array{key: string, route: string, tab?: string, permission: string}> */
    private const SECTIONS = [
        ['key' => 'general', 'route' => 'system-settings.index', 'tab' => 'general', 'permission' => 'system-settings.view'],
        ['key' => 'localization', 'route' => 'system-settings.index', 'tab' => 'localization', 'permission' => 'system-settings.view'],
        ['key' => 'appearance', 'route' => 'system-settings.index', 'tab' => 'appearance', 'permission' => 'system-settings.view'],
        ['key' => 'security', 'route' => 'system-settings.index', 'tab' => 'security', 'permission' => 'system-settings.view'],
        ['key' => 'id_cards', 'route' => 'system-settings.index', 'tab' => 'id_cards', 'permission' => 'system-settings.view'],
        ['key' => 'card_templates', 'route' => 'id-card-templates.index', 'permission' => 'id_card_templates.view'],
        ['key' => 'code_rules', 'route' => 'code-rules.index', 'permission' => 'code-rules.viewAny'],
        ['key' => 'api', 'route' => 'api-management.index', 'permission' => 'api_management.view'],
    ];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Core/Settings', [
            'sections' => collect(self::SECTIONS)
                ->filter(fn (array $section): bool => $user->can($section['permission']))
                ->map(fn (array $section): array => [
                    'key' => $section['key'],
                    'href' => route($section['route']).(isset($section['tab']) ? '?tab='.$section['tab'] : ''),
                ])
                ->values(),
        ]);
    }
}
