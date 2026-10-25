<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageCardTemplates;
use App\Http\Controllers\Controller;
use App\Models\CardTemplate;
use App\Models\CardTemplateVersion;
use App\Models\User;
use App\Services\CardRenderer;
use App\Support\CardTemplateConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Core/CardTemplates (ADR 0010): the templates, their versions and the one
 * renderer's preview. Changes go through ManageCardTemplates.
 */
class CardTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $templates = CardTemplate::query()->with('versions')->orderBy('code')->get();
        $names = User::query()->whereIn('id', $templates->flatMap(fn (CardTemplate $t) => $t->versions->flatMap(fn ($v) => [$v->created_by, $v->activated_by]))->filter()->unique())->pluck('name', 'id');
        $manage = $user->can('card_templates.manage');

        return Inertia::render('Core/CardTemplates', [
            'templates' => $templates->map(fn (CardTemplate $template): array => [
                'id' => $template->id, 'code' => $template->code, 'name_en' => $template->name_en, 'name_am' => $template->name_am,
                'new_version_url' => $manage && ! $template->versions->contains('status', CardTemplateVersion::DRAFT) ? route('core.card-templates.versions.store', $template) : null,
                'versions' => $template->versions->map(fn (CardTemplateVersion $version): array => [
                    'id' => $version->id, 'version' => $version->version, 'status' => $version->status, 'config' => $version->config,
                    'created_by' => $names->get($version->created_by), 'activated_by' => $names->get($version->activated_by),
                    'activated_at' => $version->activated_at?->toIso8601String(), 'retired_at' => $version->retired_at?->toIso8601String(),
                    'preview_url' => route('core.card-templates.versions.preview', $version),
                    'save_url' => $manage && $version->status === CardTemplateVersion::DRAFT ? route('core.card-templates.versions.update', $version) : null,
                    'activate_url' => $manage && $version->status === CardTemplateVersion::DRAFT && (int) $version->created_by !== (int) $user->getKey() ? route('core.card-templates.versions.activate', $version) : null,
                    'retire_url' => $manage && $version->status !== CardTemplateVersion::RETIRED ? route('core.card-templates.versions.retire', $version) : null,
                ])->values(),
            ])->values(),
            'fields' => CardTemplateConfig::FIELDS,
            'required_fields' => CardTemplateConfig::REQUIRED_FIELDS,
            'orientations' => CardTemplateConfig::ORIENTATIONS,
            'store_url' => $manage ? route('core.card-templates.store') : null,
        ]);
    }

    public function store(Request $request, ManageCardTemplates $templates): RedirectResponse
    {
        $templates->create($request->only(['code', 'name_en', 'name_am', 'description']), $request->user());

        return back()->with('success', __('id-cards.template_saved'));
    }

    public function newVersion(Request $request, CardTemplate $cardTemplate, ManageCardTemplates $templates): RedirectResponse
    {
        $templates->newVersion($cardTemplate, $request->user());

        return back()->with('success', __('id-cards.template_saved'));
    }

    public function update(Request $request, CardTemplateVersion $version, ManageCardTemplates $templates): RedirectResponse
    {
        $templates->saveDraft($version, (array) $request->input('config', []), $request->user());

        return back()->with('success', __('id-cards.template_saved'));
    }

    public function activate(Request $request, CardTemplateVersion $version, ManageCardTemplates $templates): RedirectResponse
    {
        $templates->activate($version, $request->user());

        return back()->with('success', __('id-cards.template_activated'));
    }

    public function retire(Request $request, CardTemplateVersion $version, ManageCardTemplates $templates): RedirectResponse
    {
        $templates->retire($version, (string) $request->input('reason', ''), $request->user());

        return back()->with('success', __('id-cards.template_retired'));
    }

    /** The version drawn with made-up data, by the same renderer that prints. */
    public function preview(CardTemplateVersion $version, CardRenderer $renderer): HttpResponse
    {
        return CardSvg::response($renderer->svg((array) $version->config, $renderer->sample()));
    }
}
