<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Letters\Application\Actions\ComposeLetter;
use Modules\Letters\Application\Actions\UpdateDraftLetter;
use Modules\Letters\Application\Services\LetterheadResolver;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LetterPresenter;
use Modules\Letters\Application\Services\LetterStandards;
use Modules\Letters\Application\Services\TemplateRenderer;
use Modules\Letters\Domain\Enums\LetterPriority;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Http\Requests\ComposeLetterRequest;

/** Composing an outgoing or internal letter, from a template or blank, and editing a draft (full pages). */
final class ComposeLetterController extends Controller
{
    public function __construct(
        private readonly LetterLookups $lookups,
        private readonly LetterPresenter $presenter,
        private readonly LetterStandards $standards,
        private readonly LetterheadResolver $letterheads,
    ) {}

    public function create(Request $request): Response
    {
        $user = $request->user();
        $organizations = $this->lookups->actingOrganizations($user, 'letters.compose');
        abort_if($organizations === [], 403);
        $selected = (string) $request->string('organization_id', count($organizations) === 1 ? $organizations[0]['id'] : '');
        $selected = collect($organizations)->contains('id', $selected) ? $selected : '';

        return Inertia::render('Letters/Compose', [
            'mode' => 'create',
            'letter' => null,
            'direction' => in_array($request->string('direction')->toString(), ['outgoing', 'internal'], true) ? $request->string('direction')->toString() : 'outgoing',
            'organizations' => $organizations,
            'selected_organization_id' => $selected,
            'units' => $selected !== '' ? $this->lookups->units($selected) : [],
            'people' => $selected !== '' ? $this->lookups->people($selected) : [],
            'templates' => $selected !== '' ? $this->lookups->templatesFor($selected) : [],
            'all_organizations' => $this->lookups->allOrganizations(),
            'priorities' => LetterPriority::values(),
            'built_in_placeholders' => TemplateRenderer::BUILT_IN,
            'file_classes' => $selected !== '' ? $this->standards->fileClassesFor($selected) : [],
            'layouts' => $this->layouts($organizations),
        ]);
    }

    public function store(ComposeLetterRequest $request, ComposeLetter $compose): RedirectResponse
    {
        $letter = $compose->execute($request->validated(), $request->user());

        return redirect()->route('letters.show', $letter)->with('success', __('letters.flash.draft_saved'));
    }

    public function edit(Request $request, Letter $letter): Response
    {
        $this->authorize('update', $letter);
        $user = $request->user();

        return Inertia::render('Letters/Compose', [
            'mode' => 'edit',
            'letter' => $this->presenter->full($letter, $user),
            'direction' => $letter->direction->value,
            'organizations' => $this->lookups->actingOrganizations($user, 'letters.compose'),
            'selected_organization_id' => $letter->organization_id,
            'units' => $this->lookups->units($letter->organization_id),
            'people' => $this->lookups->people($letter->organization_id),
            'templates' => [],
            'all_organizations' => $this->lookups->allOrganizations(),
            'priorities' => LetterPriority::values(),
            'built_in_placeholders' => TemplateRenderer::BUILT_IN,
            'file_classes' => $this->standards->fileClassesFor($letter->organization_id),
            'layouts' => $this->layouts([['id' => $letter->organization_id]]),
        ]);
    }

    /**
     * The header-and-footer layouts each institution's letters may print on.
     *
     * @param  list<array<string, mixed>>  $organizations
     * @return array<string, list<array<string, mixed>>>
     */
    private function layouts(array $organizations): array
    {
        $out = [];
        foreach ($organizations as $organization) {
            $out[(string) $organization['id']] = $this->letterheads->options((string) $organization['id']);
        }

        return $out;
    }

    public function update(ComposeLetterRequest $request, Letter $letter, UpdateDraftLetter $update): RedirectResponse
    {
        $update->execute($letter, $request->validated(), $request->user());

        return redirect()->route('letters.show', $letter)->with('success', __('letters.flash.draft_saved'));
    }
}
