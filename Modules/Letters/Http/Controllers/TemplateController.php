<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Letters\Application\Actions\ArchiveTemplate;
use Modules\Letters\Application\Actions\PublishTemplate;
use Modules\Letters\Application\Actions\SaveTemplate;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Application\Services\TemplateRenderer;
use Modules\Letters\Domain\Enums\TemplateCategory;
use Modules\Letters\Domain\Models\LetterTemplate;
use Modules\Letters\Domain\Models\LetterTemplateVersion;
use Modules\Letters\Http\Requests\SaveTemplateRequest;
use Modules\Letters\Http\Requests\TemplateNoteRequest;

/** Letter templates: the catalogue, the editor page, publishing and archiving. */
final class TemplateController extends Controller
{
    public function __construct(
        private readonly LettersAccess $access,
        private readonly LetterLookups $lookups,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LetterTemplate::class);
        $user = $request->user();
        $templates = $this->access->scopeTemplates(LetterTemplate::query(), $user)->with('owner:id,code,name_en,name_am')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', '%'.$request->string('q').'%')->orWhere('title_en', 'like', '%'.$request->string('q').'%')->orWhere('title_am', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByRaw('owner_organization_id is null desc')->orderBy('title_en')->paginate(25)->withQueryString()
            ->through(fn (LetterTemplate $t): array => $this->row($t));

        return Inertia::render('Letters/Templates/Index', [
            'templates' => $templates,
            'filters' => ['q' => (string) $request->string('q'), 'status' => (string) $request->string('status')],
            'can' => ['create' => $user->can('create', LetterTemplate::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', LetterTemplate::class);

        return Inertia::render('Letters/Templates/Form', [
            'mode' => 'create',
            'template' => null,
            'owners' => $this->owners($request),
            'categories' => TemplateCategory::values(),
            'built_in_placeholders' => TemplateRenderer::BUILT_IN,
        ]);
    }

    public function store(SaveTemplateRequest $request, SaveTemplate $save): RedirectResponse
    {
        $template = $save->execute(null, $request->validated(), $request->user());

        return redirect()->route('letters.templates.show', $template)->with('success', __('letters.flash.template_saved'));
    }

    public function show(Request $request, LetterTemplate $template): Response
    {
        $this->authorize('view', $template);
        $template->load(['owner:id,code,name_en,name_am', 'versions.publisher:id,name', 'updater:id,name']);
        $user = $request->user();

        return Inertia::render('Letters/Templates/Show', [
            'template' => $this->row($template) + [
                'draft_subject' => $template->draft_subject,
                'draft_body' => $template->draft_body,
                'draft_placeholders' => $template->draft_placeholders ?? [],
                'updated_by' => $template->updater?->name,
                'updated_at' => $template->updated_at?->toIso8601String(),
                'versions' => $template->versions->map(fn (LetterTemplateVersion $v): array => [
                    'id' => $v->id, 'version_no' => $v->version_no, 'subject' => $v->subject, 'body' => $v->body, 'placeholders' => $v->placeholderList(),
                    'change_note' => $v->change_note, 'published_by' => $v->publisher?->name, 'published_at' => $v->published_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'can' => ['update' => $user->can('update', $template), 'publish' => $user->can('publish', $template), 'archive' => $user->can('archive', $template)],
        ]);
    }

    public function edit(Request $request, LetterTemplate $template): Response
    {
        $this->authorize('update', $template);
        $template->load('owner:id,code,name_en,name_am');

        return Inertia::render('Letters/Templates/Form', [
            'mode' => 'edit',
            'template' => $this->row($template) + [
                'draft_subject' => $template->draft_subject, 'draft_body' => $template->draft_body, 'draft_placeholders' => $template->draft_placeholders ?? [],
            ],
            'owners' => $this->owners($request),
            'categories' => TemplateCategory::values(),
            'built_in_placeholders' => TemplateRenderer::BUILT_IN,
        ]);
    }

    public function update(SaveTemplateRequest $request, LetterTemplate $template, SaveTemplate $save): RedirectResponse
    {
        $save->execute($template, $request->validated(), $request->user());

        return redirect()->route('letters.templates.show', $template)->with('success', __('letters.flash.template_saved'));
    }

    public function publish(TemplateNoteRequest $request, LetterTemplate $template, PublishTemplate $publish): RedirectResponse
    {
        $this->authorize('publish', $template);
        $note = $request->validated('change_note');
        $version = $publish->execute($template, is_string($note) && $note !== '' ? $note : null, $request->user());

        return back()->with('success', __('letters.flash.template_published', ['version' => $version->version_no]));
    }

    public function archive(Request $request, LetterTemplate $template, ArchiveTemplate $archive): RedirectResponse
    {
        $this->authorize('archive', $template);
        $archive->execute($template, $request->user());

        return back()->with('success', __('letters.flash.template_archived'));
    }

    /** @return array<string, mixed> */
    private function row(LetterTemplate $t): array
    {
        return [
            'id' => $t->id, 'code' => $t->code, 'title_en' => $t->title_en, 'title_am' => $t->title_am, 'category' => $t->category->value, 'language' => $t->language,
            'status' => $t->status->value, 'current_version_no' => $t->current_version_no, 'platform' => $t->isPlatform(),
            'owner' => $t->owner !== null ? ['id' => $t->owner_organization_id, 'code' => $t->owner->code, 'name_en' => $t->owner->name_en, 'name_am' => $t->owner->name_am] : null,
            'owner_organization_id' => $t->owner_organization_id,
        ];
    }

    /** Owners the user may create templates for: the platform (null) when a steward, plus institutions in scope. */
    private function owners(Request $request): array
    {
        $user = $request->user();
        $owners = $this->lookups->actingOrganizations($user, 'letters.templates.manage');

        return ['platform' => $user->can('letters.templates.manage_platform') && $this->access->isCitywide($user), 'organizations' => $owners];
    }
}
