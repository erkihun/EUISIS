<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\IdCards\CreatePrintBatchAction;
use App\Actions\IdCards\MarkCardPrintedAction;
use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IdCards\CreatePrintBatchRequest;
use App\Http\Requests\IdCards\MarkBatchPrintedRequest;
use App\Models\CardPrintBatch;
use App\Models\IdCard;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CardPrintBatchController extends Controller
{
    /** Pending cards offered per batch-builder page. */
    private const PRINT_QUEUE_LIMIT = 500;

    public function index(): Response
    {
        $this->authorize('viewAny', CardPrintBatch::class);

        $inScope = app(OrganizationScopeService::class)->employeeIdsInScope(request()->user());

        $batches = CardPrintBatch::query()
            ->with(['creator:id,name', 'printer:id,name'])
            // A scoped actor sees only batches whose every card is in scope
            // (the same rule CardPrintBatchPolicy::view applies).
            ->when($inScope !== null, fn ($query) => $query
                ->whereHas('items')
                ->whereDoesntHave('items.card', fn ($card) => $card->whereNotIn('employee_id', $inScope)))
            ->orderByDesc('created_at')
            ->paginate(25);

        return Inertia::render('PrintBatches/Index', [
            'batches' => $batches,
            'can' => [
                'create' => request()->user()?->can('create', CardPrintBatch::class),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', CardPrintBatch::class);

        $inScope = app(OrganizationScopeService::class)->employeeIdsInScope(request()->user());

        $pending = IdCard::query()
            ->where('status', CardStatus::PendingPrint->value)
            ->when($inScope !== null, fn ($query) => $query->whereIn('employee_id', $inScope));

        // Display fields only, and a bounded queue: a batch is built from the
        // oldest pending cards first, PRINT_QUEUE_LIMIT at a time.
        $pendingCards = (clone $pending)
            ->with([
                'employee:id,employee_number,full_name,name_en,current_assignment_id',
                'employee.currentAssignment:id,organization_id',
                'employee.currentAssignment.organization:id,name_en,name_am',
            ])
            ->orderBy('created_at')
            ->limit(self::PRINT_QUEUE_LIMIT)
            ->get(['id', 'card_number', 'employee_id', 'card_request_id', 'status', 'created_at']);

        return Inertia::render('PrintBatches/Create', [
            'pendingCards' => $pendingCards,
            'pendingTotal' => $pending->count(),
            'queueLimit' => self::PRINT_QUEUE_LIMIT,
        ]);
    }

    public function store(CreatePrintBatchRequest $request, CreatePrintBatchAction $createPrintBatchAction): RedirectResponse
    {
        // CreatePrintBatchRequest already rejects out-of-scope card ids.
        $batch = $createPrintBatchAction->execute($request->input('card_ids'), $request->user());

        return redirect()->route('print-batches.show', $batch)->with('success', __('id-cards.print_batch_created'));
    }

    public function show(CardPrintBatch $batch): Response
    {
        $this->authorize('view', $batch);

        $batch->load(['creator', 'printer', 'items.card.employee.currentAssignment.organization']);

        $user = request()->user();

        return Inertia::render('PrintBatches/Show', [
            'batch' => $batch,
            'can' => [
                'markPrinted' => $user?->can('markPrinted', $batch),
            ],
        ]);
    }

    public function markPrinted(MarkBatchPrintedRequest $request, CardPrintBatch $batch, MarkCardPrintedAction $markCardPrintedAction): RedirectResponse
    {
        $markCardPrintedAction->execute($batch, $request->user(), $request->input('printer_notes'));

        return back()->with('success', __('id-cards.batch_marked_printed'));
    }
}
