<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\LegalSourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\StoreLegalSourceCitationRequest;
use App\Http\Requests\OrganizationDesign\StoreLegalSourceRequest;
use App\Http\Requests\OrganizationDesign\UpdateLegalSourceRequest;
use App\Http\Requests\OrganizationDesign\UploadLegalSourceAttachmentRequest;
use App\Models\HierarchyVersion;
use App\Models\InstitutionMandate;
use App\Models\LegacyBaseline;
use App\Models\LegalInstrumentType;
use App\Models\LegalSource;
use App\Models\LegalSourceAttachment;
use App\Models\LegalSourceCitation;
use App\Models\Organization;
use App\Models\OrganizationDesignStatusHistory;
use App\Models\WorkforcePlanningMethodology;
use App\Services\OrganizationDesign\LegalSourceService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegalSourceController extends Controller
{
    public function __construct(
        private readonly LegalSourceService $sources,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LegalSource::class);
        $user = $request->user();

        $query = LegalSource::query()
            ->with(['organization:id,code,name_en,name_am', 'sourceType:id,code,name_en,name_am'])
            ->withCount(['attachments', 'citations'])
            ->when($request->string('organization_id')->toString(), fn (Builder $q, $id) => $id === 'city' ? $q->whereNull('organization_id') : $q->where('organization_id', $id))
            ->when($request->string('status')->toString(), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->string('source_type_id')->toString(), fn (Builder $q, $type) => $q->where('source_type_id', $type))
            ->when($request->string('search')->toString(), function (Builder $q, $search): void {
                $q->where(function (Builder $nested) use ($search): void {
                    $nested->where('code', ci_like_operator(), "%{$search}%")
                        ->orWhere('title_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('title_am', ci_like_operator(), "%{$search}%")
                        ->orWhere('document_number', ci_like_operator(), "%{$search}%");
                });
            })
            ->orderBy('code');
        // City-level sources belong to everyone; institution sources stay inside the scope.
        if (! $this->scope->isUnrestricted($user)) {
            $allowed = $this->scope->allowedOrganizationIds($user);
            $query->where(fn (Builder $q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $allowed));
        }

        return Inertia::render('OrganizationDesign/LegalSources/Index', [
            'sources' => $query->paginate(25)->withQueryString()->through(fn (LegalSource $s) => $this->summary($s)),
            'filters' => $request->only(['organization_id', 'status', 'source_type_id', 'search']),
            'organizations' => $this->organizationOptions($user),
            'sourceTypes' => $this->sourceTypes(),
            'statuses' => LegalSourceStatus::values(),
            'can' => [
                'create' => $user->can('legal-sources.create'),
                'createCityLevel' => $user->can('legal-sources.manageCatalog'),
            ],
        ]);
    }

    public function store(StoreLegalSourceRequest $request): RedirectResponse
    {
        $source = $this->sources->create($request->user(), $request->validated('organization_id') ?: null, $request->validated());

        return to_route('organization-design.legal-sources.show', $source)->with('flash', ['message' => __('organization-design.legal_source_created'), 'type' => 'success']);
    }

    public function show(Request $request, LegalSource $source): Response
    {
        $this->authorize('view', $source);
        $user = $request->user();
        $source->load(['organization:id,code,name_en,name_am', 'sourceType:id,code,name_en,name_am', 'creator:id,name', 'attachments.uploader:id,name', 'citations']);

        return Inertia::render('OrganizationDesign/LegalSources/Show', [
            'source' => [
                ...$this->summary($source),
                'description_en' => $source->description_en, 'description_am' => $source->description_am,
                'source_url' => $source->source_url, 'created_by' => $source->creator?->name,
            ],
            'attachments' => $source->attachments->map(fn (LegalSourceAttachment $a) => [
                'id' => $a->id, 'original_name' => $a->original_name, 'mime_type' => $a->mime_type, 'size_bytes' => $a->size_bytes,
                'label' => $a->label, 'uploaded_by' => $a->uploader?->name, 'created_at' => $a->created_at?->toISOString(),
            ]),
            'citations' => $source->citations->map(fn (LegalSourceCitation $c) => $this->citationSummary($c))->filter()->values(),
            'history' => OrganizationDesignStatusHistory::query()->where('entity_type', $source->getMorphClass())->where('entity_id', $source->getKey())
                ->with('actor:id,name')->orderByDesc('created_at')->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at->toISOString()]),
            'sourceTypes' => $this->sourceTypes(),
            'statuses' => LegalSourceStatus::values(),
            'allowedExtensions' => LegalSourceService::ALLOWED_EXTENSIONS,
            'maxAttachmentKb' => LegalSourceService::MAX_ATTACHMENT_KB,
            'can' => [
                'update' => $user->can('update', $source),
                'delete' => $user->can('delete', $source) && $source->citations->isEmpty(),
                'manageAttachments' => $user->can('manageAttachments', $source),
            ],
        ]);
    }

    public function update(UpdateLegalSourceRequest $request, LegalSource $source): RedirectResponse
    {
        $this->sources->update($request->user(), $source, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.legal_source_updated'), 'type' => 'success']);
    }

    public function changeStatus(Request $request, LegalSource $source): RedirectResponse
    {
        $this->authorize('update', $source);
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', LegalSourceStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->sources->changeStatus($request->user(), $source, LegalSourceStatus::from($data['status']), $data['reason'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.legal_source_status_changed'), 'type' => 'success']);
    }

    public function destroy(Request $request, LegalSource $source): RedirectResponse
    {
        $this->authorize('delete', $source);
        $this->sources->delete($request->user(), $source);

        return to_route('organization-design.legal-sources.index')->with('flash', ['message' => __('organization-design.legal_source_deleted'), 'type' => 'success']);
    }

    public function storeAttachment(UploadLegalSourceAttachmentRequest $request, LegalSource $source): RedirectResponse
    {
        $this->sources->addAttachment($request->user(), $source, $request->file('file'), $request->validated('label'));

        return back()->with('flash', ['message' => __('organization-design.legal_source_attachment_added'), 'type' => 'success']);
    }

    public function destroyAttachment(Request $request, LegalSource $source, LegalSourceAttachment $attachment): RedirectResponse
    {
        abort_unless($attachment->legal_source_id === $source->getKey(), 404);
        $this->authorize('manageAttachments', $source);
        $this->sources->removeAttachment($request->user(), $attachment);

        return back()->with('flash', ['message' => __('organization-design.legal_source_attachment_removed'), 'type' => 'success']);
    }

    public function download(Request $request, LegalSource $source, LegalSourceAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->legal_source_id === $source->getKey(), 404);
        $this->authorize('download', $source);

        return $this->sources->download($request->user(), $attachment);
    }

    public function storeCitation(StoreLegalSourceCitationRequest $request, LegalSource $source): RedirectResponse
    {
        $subject = $this->resolveSubject($request->validated('subject_type'), $request->validated('subject_id'));
        // The citing record decides who may cite: whoever may update it.
        $this->authorize('update', $subject);
        $this->sources->cite($request->user(), $source, $subject, $request->validated('note'));

        return back()->with('flash', ['message' => __('organization-design.legal_source_cited'), 'type' => 'success']);
    }

    public function destroyCitation(Request $request, LegalSource $source, LegalSourceCitation $citation): RedirectResponse
    {
        abort_unless($citation->legal_source_id === $source->getKey(), 404);
        $subject = $citation->subject;
        abort_unless($subject instanceof Model, 404);
        $this->authorize('update', $subject);
        $this->sources->uncite($request->user(), $citation);

        return back()->with('flash', ['message' => __('organization-design.legal_source_citation_removed'), 'type' => 'success']);
    }

    private function resolveSubject(string $type, string $id): Model
    {
        $model = match ($type) {
            'mandate' => InstitutionMandate::query()->find($id),
            'hierarchy_version' => HierarchyVersion::query()->find($id),
            'baseline' => class_exists(LegacyBaseline::class) ? LegacyBaseline::query()->find($id) : null,
            'methodology' => WorkforcePlanningMethodology::query()->find($id),
            default => null,
        };
        if ($model === null) {
            throw ValidationException::withMessages(['subject_id' => __('organization-design.legal_source_subject_missing')]);
        }

        return $model;
    }

    /** @return array<string, mixed>|null */
    private function citationSummary(LegalSourceCitation $citation): ?array
    {
        $subject = $citation->subject;
        if (! $subject instanceof Model) {
            return null;
        }
        $label = $subject->getAttribute('code') ?? $subject->getAttribute('reference_number') ?? $subject->getAttribute('version_name') ?? (string) $subject->getKey();
        $title = $subject->getAttribute('title_en') ?? $subject->getAttribute('name_en') ?? $subject->getAttribute('version_name');

        return [
            'id' => $citation->id,
            'subject_type' => class_basename($subject),
            'subject_id' => $subject->getKey(),
            'label' => $label,
            'title' => $title,
            'note' => $citation->note,
            'created_at' => $citation->created_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(LegalSource $s): array
    {
        return [
            'id' => $s->id, 'code' => $s->code, 'title_en' => $s->title_en, 'title_am' => $s->title_am, 'status' => $s->status->value,
            'source_type_id' => $s->source_type_id,
            'source_type' => $s->sourceType ? ['code' => $s->sourceType->code, 'name_en' => $s->sourceType->name_en, 'name_am' => $s->sourceType->name_am] : null,
            'organization' => $s->organization ? ['id' => $s->organization->id, 'code' => $s->organization->code, 'name_en' => $s->organization->name_en, 'name_am' => $s->organization->name_am] : null,
            'organization_id' => $s->organization_id,
            'issuing_authority' => $s->issuing_authority, 'document_number' => $s->document_number,
            'issue_date' => $s->issue_date?->toDateString(), 'effective_from' => $s->effective_from?->toDateString(), 'effective_to' => $s->effective_to?->toDateString(),
            'attachments_count' => $s->attachments_count ?? null, 'citations_count' => $s->citations_count ?? null,
        ];
    }

    /** @return list<array{id: string, code: string, name_en: string, name_am: string|null}> */
    private function sourceTypes(): array
    {
        return LegalInstrumentType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }

    /** @return list<array{id: string, code: string, name_en: string, name_am: string|null}> */
    private function organizationOptions($user): array
    {
        $query = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($query, $user, 'id');

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }
}
