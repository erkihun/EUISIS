<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\ClassificationRequest;
use App\Models\ClassificationImportBatch;
use App\Models\IsicActivity;
use App\Models\ServiceClassification;
use App\Services\OrganizationDesign\ClassificationImportService;
use App\Services\OrganizationDesign\OrganizationDesignAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * City-level classification reference data: the LOCAL service classification,
 * CPC and ISIC, by revision, with the controlled importer (brief §12–16, 96–97).
 */
class ClassificationController extends Controller
{
    public function __construct(private readonly ClassificationImportService $importer, private readonly OrganizationDesignAudit $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ServiceClassification::class);
        $user = $request->user();
        $scheme = strtoupper($request->string('scheme')->toString() ?: ServiceClassification::SCHEME_LOCAL);
        $search = $request->string('search')->toString();

        if ($scheme === 'ISIC') {
            $revisions = IsicActivity::query()->select('revision')->distinct()->orderByDesc('revision')->pluck('revision')->all();
            $revision = $request->string('revision')->toString() ?: ($revisions[0] ?? 'Rev.4');
            $rows = IsicActivity::query()->where('revision', $revision)
                ->when($search, fn ($q) => $q->where(fn ($n) => $n->where('isic_code', ci_like_operator(), "%{$search}%")->orWhere('name_en', ci_like_operator(), "%{$search}%")->orWhere('name_am', ci_like_operator(), "%{$search}%")))
                ->orderBy('isic_code')->paginate(50)->withQueryString()
                ->through(fn (IsicActivity $a) => ['id' => $a->id, 'code' => $a->isic_code, 'level' => $a->level, 'name_en' => $a->name_en, 'name_am' => $a->name_am, 'is_active' => $a->is_active, 'parent_code' => null]);
        } else {
            $revisions = ServiceClassification::query()->where('scheme', $scheme)->select('revision')->distinct()->orderByDesc('revision')->pluck('revision')->all();
            $revision = $request->string('revision')->toString() ?: ($revisions[0] ?? '');
            $rows = ServiceClassification::query()->where('scheme', $scheme)->where('revision', $revision)->with('parent:id,code')
                ->when($search, fn ($q) => $q->where(fn ($n) => $n->where('code', ci_like_operator(), "%{$search}%")->orWhere('name_en', ci_like_operator(), "%{$search}%")->orWhere('name_am', ci_like_operator(), "%{$search}%")))
                ->orderBy('level')->orderBy('sort_order')->orderBy('code')->paginate(50)->withQueryString()
                ->through(fn (ServiceClassification $c) => ['id' => $c->id, 'code' => $c->code, 'level' => $c->level, 'name_en' => $c->name_en, 'name_am' => $c->name_am, 'is_active' => $c->is_active, 'parent_code' => $c->parent?->code, 'parent_id' => $c->parent_id, 'description_en' => $c->description_en, 'description_am' => $c->description_am, 'sort_order' => $c->sort_order]);
        }

        return Inertia::render('OrganizationDesign/Classifications/Index', [
            'scheme' => $scheme,
            'revision' => $revision,
            'revisions' => $revisions,
            'rows' => $rows,
            'filters' => $request->only(['scheme', 'revision', 'search']),
            'batches' => ClassificationImportBatch::query()->where('dataset', $scheme)->with('uploader:id,name')->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (ClassificationImportBatch $b) => [...$b->only(['id', 'revision', 'file_name', 'dry_run', 'total_rows', 'created_rows', 'updated_rows', 'unchanged_rows', 'failed_rows', 'status', 'error_report']), 'uploaded_by' => $b->uploader?->name, 'at' => $b->created_at?->toISOString()]),
            'can' => ['manage' => $user->can('classifications.manage')],
        ]);
    }

    public function store(ClassificationRequest $request): RedirectResponse
    {
        $classification = ServiceClassification::query()->create($request->validated());
        $this->audit->record(AuditEventType::ClassificationSaved, $request->user(), $classification, $classification->only(['scheme', 'revision', 'code', 'name_en']));

        return back()->with('flash', ['message' => __('organization-design.classification_saved'), 'type' => 'success']);
    }

    public function update(ClassificationRequest $request, ServiceClassification $classification): RedirectResponse
    {
        $old = $classification->only(['code', 'name_en', 'name_am', 'is_active', 'parent_id', 'level']);
        $classification->fill($request->validated())->save();
        $this->audit->record(AuditEventType::ClassificationSaved, $request->user(), $classification, $classification->only(['code', 'name_en', 'name_am', 'is_active', 'parent_id', 'level']), $old);

        return back()->with('flash', ['message' => __('organization-design.classification_saved'), 'type' => 'success']);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorize('import', ServiceClassification::class);
        $data = $request->validate([
            'scheme' => ['required', Rule::in(ClassificationImportService::DATASETS)],
            'revision' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        $batch = $this->importer->import($request->user(), $data['scheme'], $data['revision'], $request->file('file'), (bool) ($data['dry_run'] ?? false));

        $message = $batch->dry_run
            ? __('organization-design.import_previewed', ['created' => $batch->created_rows, 'updated' => $batch->updated_rows, 'failed' => $batch->failed_rows])
            : __('organization-design.import_done', ['created' => $batch->created_rows, 'updated' => $batch->updated_rows, 'failed' => $batch->failed_rows]);

        return to_route('organization-design.classifications.index', ['scheme' => $data['scheme'], 'revision' => $data['revision']])
            ->with('flash', ['message' => $message, 'type' => $batch->failed_rows ? 'warning' : 'success']);
    }
}
