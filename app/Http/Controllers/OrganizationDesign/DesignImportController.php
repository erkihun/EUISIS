<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\UploadDesignImportRequest;
use App\Models\DesignImportBatch;
use App\Models\DesignImportRow;
use App\Models\LegacyBaseline;
use App\Models\Organization;
use App\Services\OrganizationDesign\Import\DesignImportDefinitions;
use App\Services\OrganizationDesign\Import\DesignImportService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class DesignImportController extends Controller
{
    public function __construct(
        private readonly DesignImportService $imports,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DesignImportBatch::class);
        $user = $request->user();

        $query = DesignImportBatch::query()
            ->with(['organization:id,code,name_en,name_am', 'uploader:id,name', 'baseline:id,reference_number'])
            ->when($request->string('organization_id')->toString(), fn (Builder $q, $id) => $q->where('organization_id', $id))
            ->when($request->string('entity_type')->toString(), fn (Builder $q, $type) => $q->where('entity_type', $type))
            ->when($request->string('status')->toString(), fn (Builder $q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at');
        $this->scope->applyOrganizationScope($query, $user);

        $baselines = LegacyBaseline::query()->whereIn('status', ['draft', 'under_review'])->orderByDesc('created_at');
        $this->scope->applyOrganizationScope($baselines, $user);

        return Inertia::render('OrganizationDesign/Imports/Index', [
            'batches' => $query->paginate(25)->withQueryString()->through(fn (DesignImportBatch $b) => $this->summary($b)),
            'filters' => $request->only(['organization_id', 'entity_type', 'status']),
            'organizations' => $this->organizationOptions($user),
            'entityTypes' => DesignImportDefinitions::TYPES,
            'statuses' => DesignImportBatch::STATUSES,
            'baselines' => $baselines->limit(100)->get(['id', 'organization_id', 'reference_number', 'title_en'])->map->only(['id', 'organization_id', 'reference_number', 'title_en']),
            'allowedExtensions' => DesignImportService::ALLOWED_EXTENSIONS,
            'maxFileKb' => DesignImportService::MAX_FILE_KB,
            'can' => ['create' => $user->can('create', DesignImportBatch::class)],
        ]);
    }

    public function store(UploadDesignImportRequest $request): RedirectResponse
    {
        $batch = $this->imports->upload($request->user(), $request->validated('organization_id'), $request->validated('entity_type'), $request->file('file'), $request->validated('baseline_id'));

        return to_route('organization-design.imports.show', $batch)->with('flash', ['message' => __('organization-design.import_uploaded'), 'type' => 'success']);
    }

    public function template(Request $request, string $entityType): HttpResponse
    {
        $this->authorize('create', DesignImportBatch::class);
        abort_unless(in_array($entityType, DesignImportDefinitions::TYPES, true), 404);
        $locale = $request->string('locale')->toString() === 'am' ? 'am' : 'en';

        return response($this->imports->templateCsv($entityType, $locale), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="design-import-'.$entityType.'-'.$locale.'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(Request $request, DesignImportBatch $batch): Response
    {
        $this->authorize('view', $batch);
        $user = $request->user();
        $batch->load(['organization:id,code,name_en,name_am', 'uploader:id,name', 'committer:id,name', 'baseline:id,reference_number']);
        $status = $request->string('row_status')->toString();

        $rows = $batch->rows()
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->paginate(50)->withQueryString()
            ->through(fn (DesignImportRow $r) => [
                'id' => $r->id, 'row_number' => $r->row_number, 'status' => $r->status, 'data' => $r->data, 'errors' => $r->errors ?? [], 'resolved' => $r->resolved ?? [],
                'legacy_code' => $r->legacy_code, 'matched_id' => $r->matched_id, 'match_source' => $r->match_source, 'created_id' => $r->created_id,
            ]);

        return Inertia::render('OrganizationDesign/Imports/Show', [
            'batch' => [...$this->summary($batch), 'error_summary' => $batch->error_summary, 'committed_by' => $batch->committer?->name, 'committed_at' => $batch->committed_at?->toISOString(), 'parsed_at' => $batch->parsed_at?->toISOString()],
            'columns' => DesignImportDefinitions::columns($batch->entity_type),
            'referenceColumns' => array_keys(DesignImportDefinitions::for($batch->entity_type)['references']),
            'rows' => $rows,
            'rowStatus' => $status,
            'rowStatuses' => [DesignImportRow::STATUS_PENDING, DesignImportRow::STATUS_VALID, DesignImportRow::STATUS_INVALID, DesignImportRow::STATUS_MAPPING_REQUIRED, DesignImportRow::STATUS_COMMITTED, DesignImportRow::STATUS_MATCHED],
            'can' => [
                'map' => $user->can('update', $batch) && in_array($batch->status, [DesignImportBatch::STATUS_VALIDATED, DesignImportBatch::STATUS_MAPPING_REQUIRED], true),
                'revalidate' => $user->can('update', $batch) && in_array($batch->status, [DesignImportBatch::STATUS_VALIDATED, DesignImportBatch::STATUS_MAPPING_REQUIRED, DesignImportBatch::STATUS_FAILED], true),
                'commit' => $user->can('commit', $batch) && $batch->status === DesignImportBatch::STATUS_VALIDATED,
                'cancel' => $user->can('update', $batch) && ! in_array($batch->status, [DesignImportBatch::STATUS_COMMITTED, DesignImportBatch::STATUS_COMMITTING, DesignImportBatch::STATUS_CANCELLED], true),
            ],
        ]);
    }

    public function map(Request $request, DesignImportBatch $batch, DesignImportRow $row): RedirectResponse
    {
        abort_unless($row->batch_id === $batch->getKey(), 404);
        $this->authorize('update', $batch);
        $data = $request->validate(['column' => ['required', 'string', 'max:60'], 'canonical_id' => ['required', 'uuid']]);
        $this->imports->mapRow($request->user(), $row, $data['column'], $data['canonical_id']);

        return back()->with('flash', ['message' => __('organization-design.import_mapped'), 'type' => 'success']);
    }

    public function revalidate(Request $request, DesignImportBatch $batch): RedirectResponse
    {
        $this->authorize('update', $batch);
        if ($batch->status === DesignImportBatch::STATUS_FAILED || $batch->total_rows === 0) {
            $this->imports->parse($batch, $request->user());
        } else {
            $this->imports->validate($batch, $request->user());
        }

        return back()->with('flash', ['message' => __('organization-design.import_revalidated'), 'type' => 'success']);
    }

    public function commit(Request $request, DesignImportBatch $batch): RedirectResponse
    {
        $this->authorize('commit', $batch);
        $this->imports->commit($request->user(), $batch);

        return back()->with('flash', ['message' => __('organization-design.import_committed'), 'type' => 'success']);
    }

    public function cancel(Request $request, DesignImportBatch $batch): RedirectResponse
    {
        $this->authorize('update', $batch);
        $this->imports->cancel($request->user(), $batch, $request->string('reason')->toString() ?: null);

        return to_route('organization-design.imports.index')->with('flash', ['message' => __('organization-design.import_cancelled'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function summary(DesignImportBatch $b): array
    {
        return [
            'id' => $b->id, 'reference_number' => $b->reference_number, 'entity_type' => $b->entity_type, 'status' => $b->status, 'file_name' => $b->file_name,
            'total_rows' => $b->total_rows, 'valid_rows' => $b->valid_rows, 'invalid_rows' => $b->invalid_rows, 'mapping_required_rows' => $b->mapping_required_rows, 'committed_rows' => $b->committed_rows, 'matched_rows' => $b->matched_rows,
            'organization' => $b->organization ? ['id' => $b->organization->id, 'code' => $b->organization->code, 'name_en' => $b->organization->name_en, 'name_am' => $b->organization->name_am] : null,
            'organization_id' => $b->organization_id,
            'baseline' => $b->baseline ? ['id' => $b->baseline->id, 'reference_number' => $b->baseline->reference_number] : null,
            'uploaded_by' => $b->relationLoaded('uploader') ? $b->uploader?->name : null, 'created_at' => $b->created_at?->toISOString(),
        ];
    }

    /** @return list<array{id: string, code: string, name_en: string, name_am: string|null}> */
    private function organizationOptions($user): array
    {
        $query = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($query, $user, 'id');

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }
}
