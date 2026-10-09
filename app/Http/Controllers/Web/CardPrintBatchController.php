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
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CardPrintBatchController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', CardPrintBatch::class);

        $batches = CardPrintBatch::query()
            ->with(['creator', 'printer'])
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

        $pendingCards = IdCard::query()
            ->with(['employee.currentAssignment.organization', 'cardRequest'])
            ->where('status', CardStatus::PendingPrint->value)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('PrintBatches/Create', [
            'pendingCards' => $pendingCards,
        ]);
    }

    public function store(CreatePrintBatchRequest $request, CreatePrintBatchAction $createPrintBatchAction): RedirectResponse
    {
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
