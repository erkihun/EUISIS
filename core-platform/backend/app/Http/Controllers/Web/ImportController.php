<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageImports;
use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\OrganizationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Core/Imports and Core/ImportBatch (ADR 0009): the import center. Lists only
 * the batches the reader's scope holds; every step is ManageImports'.
 */
class ImportController extends Controller
{
    /** The center's tabs, each a set of batch statuses. */
    private const TABS = [
        'validation' => [ImportBatch::UPLOADED, ImportBatch::VALIDATING],
        'ready' => [ImportBatch::READY, ImportBatch::REQUIRES_ATTENTION],
        'processing' => [ImportBatch::APPROVED, ImportBatch::PROCESSING],
        'completed' => [ImportBatch::COMPLETED],
        'failed' => [ImportBatch::FAILED, ImportBatch::CANCELLED],
    ];

    public function __construct(private readonly ManageImports $imports, private readonly OrganizationScope $scope) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ImportBatch::class);
        $tab = $request->validate(['tab' => ['sometimes', Rule::in(array_keys(self::TABS))]])['tab'] ?? 'ready';
        /** @var User $user */
        $user = $request->user();
        $visible = $this->visible($user);
        $counts = (clone $visible)->toBase()->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
        $page = (clone $visible)->whereIn('status', self::TABS[$tab])->latest('created_at')->paginate(20)->withQueryString();
        $names = User::query()->whereIn('id', collect($page->items())->flatMap(fn ($b) => [$b->uploaded_by, $b->approved_by])->filter()->unique())->pluck('name', 'id');

        return Inertia::render('Core/Imports', [
            'tab' => $tab,
            'counts' => collect(self::TABS)->map(fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $s) => $counts[$s] ?? 0)),
            'batches' => [
                'data' => collect($page->items())->map(fn (ImportBatch $batch): array => $this->summary($batch, $names))->values(),
                'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total(),
                    'from' => $page->firstItem(), 'to' => $page->lastItem()],
            ],
            'types' => collect(ImportBatch::TYPES)->map(fn (string $type): array => [
                'type' => $type,
                'columns' => ManageImports::definition($type)->columns(),
                'can_upload' => Gate::forUser($user)->allows('create', [ImportBatch::class, $type]),
                'template_url' => route('core.imports.template', $type),
            ])->values(),
            'urls' => ['page' => route('core.imports'), 'store' => route('core.imports.store')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(ImportBatch::TYPES)], 'file' => ['required', 'file']]);
        $batch = $this->imports->upload($data['type'], $request->file('file'), $request->user());

        return to_route('core.imports.show', $batch)->with('flash', ['message' => __('imports.uploaded'), 'type' => 'success']);
    }

    public function show(Request $request, ImportBatch $importBatch): Response
    {
        Gate::authorize('view', $importBatch);
        $filter = $request->validate(['rows' => ['sometimes', Rule::in(['all', 'invalid', 'valid', 'processed', 'failed'])]])['rows'] ?? 'all';
        /** @var User $user */
        $user = $request->user();
        $columns = ManageImports::definition($importBatch->type)->columns();
        $rows = ImportRow::query()->where('import_batch_id', $importBatch->id)
            ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))->orderBy('row_number')->paginate(50)->withQueryString();
        $names = User::query()->whereIn('id', array_filter([$importBatch->uploaded_by, $importBatch->approved_by, $importBatch->cancelled_by]))->pluck('name', 'id');

        return Inertia::render('Core/ImportBatch', [
            'batch' => $this->summary($importBatch, $names) + [
                'error_summary' => $importBatch->error_summary,
                'cancel_reason' => $importBatch->cancel_reason,
                'cancelled_by' => $names->get($importBatch->cancelled_by),
                'hierarchy_version_url' => $importBatch->hierarchy_version_id === null ? null : route('hierarchy-versions.show', $importBatch->hierarchy_version_id),
            ],
            'columns' => $columns,
            'rows' => [
                // A national ID shows only as its last four characters, here as everywhere.
                'data' => collect($rows->items())->map(fn (ImportRow $row): array => [
                    'row_number' => $row->row_number,
                    'status' => $row->status,
                    'values' => collect($columns)->mapWithKeys(fn (string $column): array => [$column => $column === 'national_id' && ($row->payload[$column] ?? null) !== null
                        ? '•••'.mb_substr((string) $row->payload[$column], -4) : ($row->payload[$column] ?? null)])->all(),
                    'errors' => $row->errors ?? [],
                    'result_type' => $row->result_type,
                ])->values(),
                'meta' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total(),
                    'from' => $rows->firstItem(), 'to' => $rows->lastItem()],
            ],
            'filter' => $filter,
            'can' => ['approve' => Gate::forUser($user)->allows('approve', $importBatch), 'cancel' => Gate::forUser($user)->allows('cancel', $importBatch)],
            'urls' => [
                'page' => route('core.imports.show', $importBatch),
                'approve' => route('core.imports.approve', $importBatch),
                'cancel' => route('core.imports.cancel', $importBatch),
                'errors' => route('core.imports.errors', $importBatch),
                'index' => route('core.imports'),
            ],
        ]);
    }

    public function approve(Request $request, ImportBatch $importBatch): RedirectResponse
    {
        $data = $request->validate(['acknowledge_invalid' => ['sometimes', 'boolean']]);
        $this->imports->approve($importBatch, (bool) ($data['acknowledge_invalid'] ?? false), $request->user());

        return back()->with('flash', ['message' => __('imports.approved'), 'type' => 'success']);
    }

    public function cancel(Request $request, ImportBatch $importBatch): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->imports->cancel($importBatch, $data['reason'], $request->user());

        return back()->with('flash', ['message' => __('imports.cancelled'), 'type' => 'success']);
    }

    public function template(Request $request, string $type): StreamedResponse
    {
        Gate::authorize('viewAny', ImportBatch::class);
        abort_unless(in_array($type, ImportBatch::TYPES, true), 404);

        return $this->imports->template($type);
    }

    public function errors(Request $request, ImportBatch $importBatch): StreamedResponse
    {
        Gate::authorize('view', $importBatch);

        return $this->imports->errorReport($importBatch, $request->user());
    }

    /** The batches whose uploader's scope the reader's scope holds (all of them for a city-wide reader). */
    private function visible(User $user)
    {
        $organizations = $this->scope->organizationIds($user);

        return ImportBatch::query()->when($organizations !== null, fn ($q) => $q->whereIn('scope_organization_id', $organizations));
    }

    /** @return array<string, mixed> */
    private function summary(ImportBatch $batch, $names): array
    {
        return [
            'id' => $batch->id,
            'type' => $batch->type,
            'status' => $batch->status,
            'source_filename' => $batch->source_filename,
            'file_size' => $batch->file_size,
            'checksum' => $batch->checksum,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'invalid_rows' => $batch->invalid_rows,
            'processed_rows' => $batch->processed_rows,
            'failed_rows' => $batch->failed_rows,
            'uploaded_by' => $names->get($batch->uploaded_by),
            'uploaded_at' => $batch->created_at?->toIso8601String(),
            'approved_by' => $names->get($batch->approved_by),
            'approved_at' => $batch->approved_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'url' => route('core.imports.show', $batch),
        ];
    }
}
