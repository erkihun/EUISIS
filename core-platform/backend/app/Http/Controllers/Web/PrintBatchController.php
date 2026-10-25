<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManagePrintBatches;
use App\Http\Controllers\Controller;
use App\Models\CardPrintBatch;
use App\Models\CardPrintBatchItem;
use App\Models\IdCard;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CardRenderer;
use App\Services\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EUISIS PrintBatches/* over the Core print batches (ADR 0010). A reader sees
 * a batch only when every card in it is inside their organizations; the print
 * sheet is drawn by the one card renderer and its opening is audited.
 */
class PrintBatchController extends Controller
{
    public function __construct(private readonly OrganizationScope $scope) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:64'], 'status' => ['nullable', Rule::in([CardPrintBatch::DRAFT, CardPrintBatch::COMPLETED, CardPrintBatch::CANCELLED])]]);
        $page = $this->visible(CardPrintBatch::query(), $request->user())->withCount(['items', 'items as printed_count' => fn ($q) => $q->where('status', 'printed')])
            ->when($filters['search'] ?? null, fn ($q, string $search) => $q->where('batch_number', 'like', "%{$search}%"))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderByDesc('created_at')->paginate(25)->withQueryString();
        $names = User::query()->whereIn('id', collect($page->items())->pluck('created_by')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('PrintBatches/Index', [
            'batches' => [
                'data' => collect($page->items())->map(fn (CardPrintBatch $batch): array => [
                    'id' => $batch->id, 'batch_number' => $batch->batch_number, 'status' => $batch->status,
                    'total_cards' => (int) $batch->items_count, 'printed_count' => (int) $batch->printed_count,
                    'printed_at' => $batch->printed_at?->toIso8601String(), 'created_at' => $batch->created_at?->toIso8601String(),
                    'created_by' => $names->has($batch->created_by) ? ['name' => $names[$batch->created_by]] : null,
                ])->values(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $filters['status'] ?? ''],
            'can' => ['create' => $request->user()->can('card_print_batches.manage')],
        ]);
    }

    public function create(Request $request): Response
    {
        $cards = $this->inScope(IdCard::query(), $request->user())->with('employee.currentAssignment.organization')
            ->where('is_current', true)->whereNull('current_print_batch_id')
            ->where(fn ($q) => $q->where('status', IdCard::PENDING_PRINT)->orWhere(fn ($r) => $r->where('status', IdCard::PRINTED)->where('reprint_required', true)))
            ->orderBy('created_at')->limit(ManagePrintBatches::MAX_CARDS)->get();

        return Inertia::render('PrintBatches/Create', [
            'pendingCards' => $cards->map(fn (IdCard $card): array => [
                'id' => $card->id, 'card_number' => $card->card_number, 'status' => $card->status,
                'employee' => $card->employee === null ? null : [
                    'employee_number' => $card->employee->employee_number, 'full_name' => $card->employee->full_name,
                    'current_assignment' => ['organization' => $card->employee->currentAssignment?->organization?->only(['name_en'])],
                ],
            ])->values(),
        ]);
    }

    public function store(Request $request, ManagePrintBatches $batches): RedirectResponse
    {
        $data = $request->validate(['card_ids' => ['required', 'array', 'max:'.ManagePrintBatches::MAX_CARDS], 'card_ids.*' => ['uuid'], 'printer_notes' => ['nullable', 'string', 'max:1000']]);
        $batch = $batches->create($data['card_ids'], $data['printer_notes'] ?? null, $request->user());

        return to_route('print-batches.show', $batch)->with('success', __('id-cards.print_batch_created'));
    }

    public function show(Request $request, CardPrintBatch $printBatch): Response
    {
        $this->authorizeBatch($request->user(), $printBatch);
        $items = $printBatch->items()->with('card.employee')->orderBy('id')->get();
        $names = User::query()->whereIn('id', array_filter([$printBatch->created_by, $printBatch->printed_by]))->pluck('name', 'id');
        $user = $request->user();

        return Inertia::render('PrintBatches/Show', [
            'batch' => [
                'id' => $printBatch->id, 'batch_number' => $printBatch->batch_number, 'status' => $printBatch->status,
                'total_cards' => $items->count(), 'printed_count' => $items->where('status', 'printed')->count(), 'spoiled_count' => $items->where('status', 'spoiled')->count(),
                'printer_notes' => $printBatch->printer_notes, 'printed_at' => $printBatch->printed_at?->toIso8601String(), 'created_at' => $printBatch->created_at?->toIso8601String(),
                'created_by' => $names->has($printBatch->created_by) ? ['name' => $names[$printBatch->created_by]] : null,
                'printed_by' => $names->has($printBatch->printed_by) ? ['name' => $names[$printBatch->printed_by]] : null,
                'items' => $items->map(fn (CardPrintBatchItem $item): array => [
                    'id' => $item->id, 'status' => $item->status, 'spoiled' => $item->status === 'spoiled', 'reprint_reason' => $item->reprint_reason,
                    'kind' => $item->kind, 'qc_status' => $item->qc_status,
                    'card' => $item->card === null ? null : [
                        'id' => $item->card->id, 'card_number' => $item->card->card_number, 'status' => $item->card->status,
                        'employee' => $item->card->employee?->only(['employee_number', 'full_name']),
                    ],
                ])->values(),
            ],
            'can' => ['markPrinted' => $printBatch->status === CardPrintBatch::DRAFT && $user->can('card_print_batches.manage') && $user->can('id-cards.print')],
            // Core only (ADR 0010): the print sheet from the one card renderer, and cancelling a draft.
            'sheetUrl' => $printBatch->status === CardPrintBatch::DRAFT && $user->can('card_print_batches.manage') ? route('print-batches.sheet', $printBatch) : null,
            'cancelUrl' => $printBatch->status === CardPrintBatch::DRAFT && $user->can('card_print_batches.manage') ? route('print-batches.cancel', $printBatch) : null,
        ]);
    }

    public function markPrinted(Request $request, CardPrintBatch $printBatch, ManagePrintBatches $batches): RedirectResponse
    {
        $this->authorizeBatch($request->user(), $printBatch);
        $data = $request->validate(['printer_notes' => ['nullable', 'string', 'max:1000'], 'spoiled_item_ids' => ['sometimes', 'array'], 'spoiled_item_ids.*' => ['uuid']]);
        $batches->markPrinted($printBatch, $data['printer_notes'] ?? null, $data['spoiled_item_ids'] ?? [], $request->user());

        return back()->with('success', __('id-cards.print_batch_printed'));
    }

    public function cancel(Request $request, CardPrintBatch $printBatch, ManagePrintBatches $batches): RedirectResponse
    {
        $this->authorizeBatch($request->user(), $printBatch);
        $batches->cancel($printBatch, (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'], $request->user());

        return to_route('print-batches.index')->with('success', __('id-cards.print_batch_cancelled'));
    }

    /** The batch's cards drawn for printing, one per page at card size. */
    public function sheet(Request $request, CardPrintBatch $printBatch, CardRenderer $renderer, AuditWriter $audit): HttpResponse
    {
        $this->authorizeBatch($request->user(), $printBatch);
        abort_unless($request->user()->can('card_print_batches.manage') && $printBatch->status === CardPrintBatch::DRAFT, 403);
        $config = (array) $printBatch->templateVersion?->config;
        $landscape = ($config['orientation'] ?? 'landscape') === 'landscape';
        $cards = $printBatch->items()->with('card')->where('status', 'queued')->orderBy('id')->get()
            ->map(fn (CardPrintBatchItem $item): string => '<section class="card">'.$renderer->svg($config, $renderer->dataFor($item->card)).'</section>')->implode('');
        $audit->writeFor('card_print_batch.sheet_opened', $request->user(), 'CardPrintBatch', (string) $printBatch->id, null);
        $size = $landscape ? '85.6mm 54mm' : '54mm 85.6mm';
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>'.e($printBatch->batch_number).'</title>'
            ."<style>@page{size:{$size};margin:0}body{margin:0}.card{page-break-after:always;width:".($landscape ? '85.6mm' : '54mm').'}.card svg{width:100%;height:auto;display:block}</style>'
            .'</head><body>'.$cards.'</body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
        ]);
    }

    /** @param Builder<CardPrintBatch> $query */
    private function visible(Builder $query, User $user): Builder
    {
        $ids = $this->scope->organizationIds($user);

        return $ids === null ? $query : $query->whereDoesntHave('items.card', fn ($card) => $card->whereDoesntHave('employee.currentAssignment', fn ($a) => $a->whereIn('organization_id', $ids)));
    }

    private function authorizeBatch(User $user, CardPrintBatch $batch): void
    {
        abort_unless($user->can('card_print_batches.view') && $this->visible(CardPrintBatch::query()->whereKey($batch->id), $user)->exists(), 404);
    }

    /** @param Builder<IdCard> $query */
    private function inScope(Builder $query, User $user): Builder
    {
        $ids = $this->scope->organizationIds($user);

        return $ids === null ? $query : $query->whereHas('employee.currentAssignment', fn ($a) => $a->whereIn('organization_id', $ids));
    }
}
