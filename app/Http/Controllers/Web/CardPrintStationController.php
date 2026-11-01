<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\IdCards\CreatePrintBatchAction;
use App\Actions\IdCards\PreparePrintBatchAction;
use App\Actions\IdCards\RecordPrintResultsAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\CardPrintBatch;
use App\Models\CardPrintBatchItem;
use App\Models\CardPrinterProfile;
use App\Services\IdCards\IdCardPrintBatchExporter;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The print station for one batch: freeze the cards, send them to a printer
 * profile (or download them), then record which cards came out right.
 */
class CardPrintStationController extends Controller
{
    public function show(Request $request, CardPrintBatch $batch, PreparePrintBatchAction $prepare): Response
    {
        $this->authorize('view', $batch);
        $user = $request->user();

        return Inertia::render('PrintBatches/Station', [
            'batch' => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'status' => $batch->status,
                'total_cards' => $batch->total_cards,
                'printed_count' => $batch->printed_count,
                'spoiled_count' => $batch->spoiled_count,
                'printer_profile_id' => $batch->printer_profile_id,
                'printer_notes' => $batch->printer_notes,
            ],
            'items' => $this->items($batch)->map(fn (CardPrintBatchItem $item): array => [
                'id' => $item->id,
                'status' => $item->status,
                'reprint_reason' => $item->reprint_reason,
                'prepared' => $item->snapshot !== null && $item->snapshot->printed_at === null,
                'card_id' => $item->id_card_id,
                'card_number' => $item->card?->card_number,
                // Display fields only (SEC-9): never contact or birth data.
                'employee_number' => $item->card?->employee?->employee_number,
                'employee_name' => $item->card?->employee?->full_name,
                'organization' => $item->card?->employee?->currentAssignment?->organization?->name_en,
            ])->values(),
            'toPrepare' => $prepare->stale($batch)->count(),
            'profiles' => CardPrinterProfile::query()->orderByDesc('is_default')->orderBy('name')->get()->map->presentation(),
            'can' => [
                'print' => $user->can('markPrinted', $batch),
                'manageProfiles' => $this->canManageProfiles($request),
                'requeue' => $user->can('create', CardPrintBatch::class),
            ],
            'limits' => ['chunk' => PreparePrintBatchAction::CHUNK],
        ]);
    }

    public function prepare(Request $request, CardPrintBatch $batch, PreparePrintBatchAction $prepare): RedirectResponse
    {
        $this->authorize('markPrinted', $batch);
        try {
            $prepare->execute($batch, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['batch' => $exception->getMessage()]);
        }

        // The station re-reads `toPrepare` and calls again until it is zero.
        return back();
    }

    /** Choosing a profile is remembered on the batch, so a restart picks it up. */
    public function useProfile(Request $request, CardPrintBatch $batch): RedirectResponse
    {
        $this->authorize('markPrinted', $batch);
        $data = $request->validate(['printer_profile_id' => ['nullable', 'uuid', Rule::exists('card_printer_profiles', 'id')]]);
        $batch->update(['printer_profile_id' => $data['printer_profile_id'] ?? null]);

        return back();
    }

    /**
     * The document a browser sends to the printer: the batch's frozen card
     * faces laid out for one profile, at exact physical size.
     */
    public function sheet(Request $request, CardPrintBatch $batch): Response
    {
        $this->authorize('markPrinted', $batch);
        [$from, $to] = $this->range($request);
        $profile = CardPrinterProfile::query()->find($request->string('profile')->toString())
            ?? $batch->printerProfile
            ?? new CardPrinterProfile(['name' => '', 'mode' => 'card', 'sides' => 'both', 'duplex_order' => 'interleaved', 'scale_percent' => 100, 'offset_x_mm' => 0, 'offset_y_mm' => 0, 'margin_mm' => 6, 'gap_mm' => 3, 'cut_guides' => true, 'mirror_backs' => true]);

        $cards = $this->items($batch)
            ->filter(fn (CardPrintBatchItem $item): bool => $item->status === 'pending' && $item->snapshot !== null && $item->snapshot->printed_at === null)
            ->values()
            ->slice($from - 1, $to - $from + 1)
            ->values();
        $first = $cards->first()?->snapshot;
        $width = (float) ($first?->width_mm ?? 85.6);
        $height = (float) ($first?->height_mm ?? 54);

        return Inertia::render('PrintBatches/Sheet', [
            'batch' => ['id' => $batch->id, 'batch_number' => $batch->batch_number],
            'profile' => $profile->presentation(),
            'card' => ['width_mm' => $width, 'height_mm' => $height],
            'grid' => $profile->grid($width, $height),
            'cards' => $cards->map(fn (CardPrintBatchItem $item): array => [
                'id' => $item->id,
                'card_number' => $item->card?->card_number,
                'front' => route('id-cards.print-artifact', [$item->id_card_id, $item->id_card_print_snapshot_id, 'front']),
                'back' => route('id-cards.print-artifact', [$item->id_card_id, $item->id_card_print_snapshot_id, 'back']),
            ]),
            'range' => ['from' => $from, 'to' => $from + $cards->count() - 1],
        ]);
    }

    public function download(Request $request, CardPrintBatch $batch, IdCardPrintBatchExporter $exporter, WriteAuditLogAction $audit): BinaryFileResponse
    {
        $this->authorize('markPrinted', $batch);
        [$from, $to] = $this->range($request);
        $items = $this->items($batch)
            ->filter(fn (CardPrintBatchItem $item): bool => $item->snapshot !== null)
            ->values()
            ->slice($from - 1, $to - $from + 1);
        abort_if($items->isEmpty(), 404);

        $path = $exporter->zip($batch, $items);
        $audit->execute(
            AuditEventType::ExportPerformed, $request->user(), $batch, null,
            newValues: ['export' => 'id_card_print_batch', 'batch_number' => $batch->batch_number, 'cards' => $items->count(), 'from' => $from],
        );

        return response()->download($path, $batch->batch_number.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])
            ->deleteFileAfterSend();
    }

    public function results(Request $request, CardPrintBatch $batch, RecordPrintResultsAction $record): RedirectResponse
    {
        $this->authorize('markPrinted', $batch);
        $data = $request->validate([
            'printed' => ['array'],
            'printed.*' => ['uuid'],
            'spoiled' => ['array'],
            'spoiled.*.item_id' => ['required', 'uuid'],
            'spoiled.*.reason' => ['required', 'string', 'max:255'],
            'printer_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $result = $record->execute(
                $batch, $request->user(), $data['printed'] ?? [],
                collect($data['spoiled'] ?? [])->pluck('reason', 'item_id')->all(),
                $data['printer_notes'] ?? null,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['batch' => $exception->getMessage()]);
        }
        if ($result['failed'] !== []) {
            // Usually a snapshot that went stale: preparing again refreshes it.
            return back()->with('error', __('id-cards.print_results_failed', ['cards' => implode(', ', $result['failed'])]));
        }

        return back()->with('success', __('id-cards.print_results_saved', ['printed' => $result['printed'], 'spoiled' => $result['spoiled']]));
    }

    /** Spoiled cards straight into a fresh batch, so nothing is left unprinted. */
    public function requeue(Request $request, CardPrintBatch $batch, CreatePrintBatchAction $create): RedirectResponse
    {
        $this->authorize('view', $batch);
        $this->authorize('create', CardPrintBatch::class);
        $cardIds = $batch->items()->where('status', 'spoiled')->pluck('id_card_id')->unique()->values()->all();
        if ($cardIds === []) {
            return back()->with('error', __('id-cards.print_requeue_none'));
        }
        try {
            $next = $create->execute($cardIds, $request->user());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        $next->update(['printer_profile_id' => $batch->printer_profile_id]);

        return to_route('print-batches.station', $next)->with('success', __('id-cards.print_batch_created'));
    }

    /** @return Collection<int, CardPrintBatchItem> in print order */
    private function items(CardPrintBatch $batch): Collection
    {
        return $batch->items()
            ->with([
                'snapshot',
                'card:id,card_number,employee_id,status',
                'card.employee:id,employee_number,full_name,current_assignment_id',
                'card.employee.currentAssignment:id,organization_id',
                'card.employee.currentAssignment.organization:id,name_en',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /** @return array{0: int, 1: int} a 1-based, inclusive card range */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'integer', 'min:1'],
            'to' => ['nullable', 'integer', 'min:1', 'gte:from'],
        ]);
        $from = (int) ($data['from'] ?? 1);

        return [$from, (int) ($data['to'] ?? CreatePrintBatchAction::MAX_CARDS)];
    }

    private function canManageProfiles(Request $request): bool
    {
        return $request->user()->can('id-cards.print') || $request->user()->can('cards.manage');
    }
}
