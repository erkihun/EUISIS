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
        /*
         * Card artwork includes the official seal and authorizing signature,
         * which are forgery material. Only people who work with cards may
         * fetch it: card staff for the active template, template managers for
         * any template. Being signed in is not enough.
         */
        $user = $request->user();
        $cardStaff = $user->can('id-cards.view') || $user->can('cards.view');
        abort_unless(($cardStaff && $template->is_default && $template->status === 'active') || $user->can('id_card_templates.view'), 403);
        abort_unless(in_array($side, ['front', 'back', 'logo-primary', 'logo-secondary', 'seal', 'signature'], true), 404);
        $path = match (true) {
            str_starts_with($side, 'logo-') => $template->{'logo_'.substr($side, 5).'_path'},
            in_array($side, ['seal', 'signature'], true) => $template->{$side.'_path'},
            default => $template->{$side.'_background_path'},
        };
        abort_unless($templates->safePath($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $side.'.png', [
            'Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
