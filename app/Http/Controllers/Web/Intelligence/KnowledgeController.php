<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\IntelligenceKnowledgeDocument;
use App\Models\IntelligenceKnowledgeQuestion;
use App\Models\User;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\IntelligenceLabels;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\Knowledge\KnowledgeAssistant;
use App\Services\Intelligence\Knowledge\KnowledgeDocumentService;
use App\Services\Intelligence\Knowledge\KnowledgeRetriever;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Knowledge Assistant (docs/intelligence-knowledge.md): the question page
 * with the viewer's own recent questions, and — for governance managers —
 * the document list with its inline create form and the approve / retire /
 * delete actions. An answer comes back as a flash prop of the same page, so
 * the question stays under the form and a reload never replays the POST.
 */
final class KnowledgeController extends Controller
{
    private const PER_PAGE = 25;

    private const RECENT_QUESTIONS = 10;

    public function __construct(
        private readonly KnowledgeAssistant $assistant,
        private readonly KnowledgeRetriever $retriever,
        private readonly KnowledgeDocumentService $documents,
        private readonly IntelligenceSettings $settings,
        private readonly LLMGateway $gateway,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Intelligence/Knowledge/Index', [
            'labels' => IntelligenceLabels::dictionary(),
            'answer' => $request->session()->get('knowledge_answer'),
            'questions' => IntelligenceKnowledgeQuestion::query()->where('user_id', $user->id)->orderByDesc('created_at')->limit(self::RECENT_QUESTIONS)
                ->get(['id', 'question', 'mode', 'grounded', 'citations', 'created_at'])
                ->map(fn (IntelligenceKnowledgeQuestion $q): array => [
                    'id' => $q->id, 'question' => $q->question, 'mode' => $q->mode, 'grounded' => $q->grounded,
                    'citations' => count($q->citations ?? []), 'asked_at' => $q->created_at->toIso8601String(),
                ])->all(),
            'documents_count' => $this->retriever->visibleDocuments($user)->count(),
            'ai' => $this->gateway->status(),
            'knowledge_enabled' => $this->settings->knowledgeEnabled(),
            'knowledge_outbound' => in_array('knowledge_documents', $this->settings->outboundDataClasses(), true),
            'can' => ['manage' => $user->can(IntelligencePermissions::GOVERNANCE_MANAGE)],
        ]);
    }

    public function ask(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'question' => ['required', 'string', 'min:5', 'max:1000'],
            'locale' => ['nullable', Rule::in(IntelligenceKnowledgeDocument::LANGUAGES)],
        ]);

        $answer = $this->assistant->ask($user, $data['question'], $data['locale'] ?? (string) app()->getLocale());

        return redirect()->route('intelligence.knowledge.index')->with('knowledge_answer', $answer);
    }

    public function documents(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(IntelligenceKnowledgeDocument::STATUSES)],
            'classification' => ['nullable', Rule::in(IntelligenceKnowledgeDocument::CLASSIFICATIONS)],
        ]);

        $query = IntelligenceKnowledgeDocument::query()->with(['approver:id,name', 'author:id,name'])->withCount('chunks')->orderBy('title');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['classification'])) {
            $query->where('classification', $filters['classification']);
        }
        $counts = IntelligenceKnowledgeDocument::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return Inertia::render('Intelligence/Knowledge/Documents', [
            'labels' => IntelligenceLabels::dictionary(),
            'filters' => ['status' => $filters['status'] ?? '', 'classification' => $filters['classification'] ?? ''],
            'options' => [
                'statuses' => IntelligenceKnowledgeDocument::STATUSES,
                'classifications' => IntelligenceKnowledgeDocument::CLASSIFICATIONS,
                'languages' => IntelligenceKnowledgeDocument::LANGUAGES,
            ],
            'counts' => ['draft' => (int) ($counts['draft'] ?? 0), 'approved' => (int) ($counts['approved'] ?? 0), 'retired' => (int) ($counts['retired'] ?? 0)],
            'documents' => $query->paginate(self::PER_PAGE)->withQueryString()->through(fn (IntelligenceKnowledgeDocument $d): array => [
                'id' => $d->id, 'title' => $d->title, 'slug' => $d->slug, 'source_type' => $d->source_type, 'source_path' => $d->source_path,
                'classification' => $d->classification, 'organization_id' => $d->organization_id, 'required_permission' => $d->required_permission,
                'language' => $d->language, 'status' => $d->status, 'version' => $d->version, 'chunks' => $d->chunks_count,
                'approved_by' => $d->approver?->name, 'approved_at' => $d->approved_at?->toIso8601String(), 'created_by' => $d->author?->name,
                'updated_at' => $d->updated_at?->toIso8601String(),
            ]),
            'scoped' => ! $this->scope->isUnrestricted($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate($this->rules($user));

        $this->documents->create($user, $data);

        return back()->with('flash', ['message' => __('intelligence_knowledge.flash.document_created'), 'type' => 'success']);
    }

    public function update(Request $request, IntelligenceKnowledgeDocument $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            ...array_map(fn (array $rules): array => ['sometimes', ...$rules], $this->rules($user)),
            'status' => ['nullable', Rule::in(['approved', 'retired'])],
        ]);
        $status = $data['status'] ?? null;
        unset($data['status']);

        if ($data !== []) {
            $this->documents->update($user, $document, $data);
        }
        $message = 'document_updated';
        if ($status === 'approved' && $document->status !== 'approved') {
            $this->documents->approve($user, $document);
            $message = 'document_approved';
        } elseif ($status === 'retired' && $document->status !== 'retired') {
            $this->documents->retire($user, $document);
            $message = 'document_retired';
        }

        return back()->with('flash', ['message' => __('intelligence_knowledge.flash.'.$message), 'type' => 'success']);
    }

    public function destroy(Request $request, IntelligenceKnowledgeDocument $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->documents->delete($user, $document);

        return redirect()->route('intelligence.knowledge.documents.index')->with('flash', ['message' => __('intelligence_knowledge.flash.document_deleted'), 'type' => 'success']);
    }

    /** @return array<string, list<mixed>> */
    private function rules(User $user): array
    {
        return [
            'title' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:200000'],
            'classification' => ['required', Rule::in(IntelligenceKnowledgeDocument::CLASSIFICATIONS)],
            'language' => ['required', Rule::in(IntelligenceKnowledgeDocument::LANGUAGES)],
            'organization_id' => [
                'nullable', 'uuid', Rule::exists('organizations', 'id'),
                // The actor may scope a document only to an organization they can reach themselves.
                fn (string $attribute, mixed $value, \Closure $fail) => $this->scope->canAccessOrganization($user, (string) $value) || $fail(__('intelligence_knowledge.validation.organization_out_of_scope')),
            ],
            'required_permission' => [
                'nullable', 'string', 'max:120',
                fn (string $attribute, mixed $value, \Closure $fail) => PermissionCatalog::has((string) $value) || $fail(__('intelligence_knowledge.validation.unknown_permission')),
            ],
        ];
    }
}
