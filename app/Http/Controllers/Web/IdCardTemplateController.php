<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\IdCards\SaveIdCardTemplateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\IdCards\SaveIdCardTemplateRequest;
use App\Models\IdCardTemplate;
use App\Services\IdCards\IdCardTemplateService;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdCardTemplateController extends Controller
{
    public function index(Request $request, IdCardTemplateService $templates, SystemSettingsService $settings): Response
    {
        $this->authorize('id_card_templates.view');

        return Inertia::render('SystemSettings/IdCardTemplates', $templates->managementData(
            $request->user(), (int) $settings->get('security', 'max_upload_size_mb', 10),
        ));
    }

    public function store(SaveIdCardTemplateRequest $request, SaveIdCardTemplateAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user());

        return back()->with('success', __('id-card-templates.saved'));
    }

    public function update(SaveIdCardTemplateRequest $request, IdCardTemplate $template, SaveIdCardTemplateAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user(), $template);

        return back()->with('success', __('id-card-templates.saved'));
    }

    public function destroy(Request $request, IdCardTemplate $template, SaveIdCardTemplateAction $action): RedirectResponse
    {
        $this->authorize('id_card_templates.delete');
        $action->delete($template, $request->user());

        return back()->with('success', __('id-card-templates.deleted'));
    }

    public function setDefault(Request $request, IdCardTemplate $template, SaveIdCardTemplateAction $action): RedirectResponse
    {
        $action->setDefault($template, $request->user());

        return back()->with('success', __('id-card-templates.saved'));
    }

    public function background(Request $request, IdCardTemplate $template, string $side, IdCardTemplateService $templates): StreamedResponse
    {
        // Active artwork is available to authenticated card consumers; drafts require template access.
        abort_unless(($template->is_default && $template->status === 'active') || $request->user()->can('id_card_templates.view'), 403);
        abort_unless(in_array($side, ['front', 'back'], true), 404);
        $path = $template->{$side.'_background_path'};
        abort_unless($templates->safePath($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $side.'.png', [
            'Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
