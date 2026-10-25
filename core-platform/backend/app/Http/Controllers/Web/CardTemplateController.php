<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageCardTemplates;
use App\Http\Controllers\Controller;
use App\Models\CardTemplate;
use App\Models\CardTemplateVersion;
use App\Services\CardRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

/**
 * The print record of card templates (ADR 0010): versions and the one renderer's
 * preview. Changes go through ManageCardTemplates; the designer page is the EUISIS
 * one (IdCardTemplateController), mirrored into these versions.
 */
class CardTemplateController extends Controller
{
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
