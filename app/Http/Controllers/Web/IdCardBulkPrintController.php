<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\IdCards\CreatePrintBatchAction;
use App\Http\Controllers\Controller;
use App\Models\CardPrintBatch;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Services\IdCards\IdCardPrintSelection;
use App\Services\OrganizationScope\OrganizationScopeService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk printing: pick many cards at once — a whole organization, one unit
 * and its sub-units, everything waiting for a reprint — and turn them into a
 * print batch for the print station.
 */
class IdCardBulkPrintController extends Controller
{
    public function index(Request $request, IdCardPrintSelection $selection, OrganizationScopeService $scope): Response
    {
        $this->authorize('create', CardPrintBatch::class);
        $filters = $this->filters($request);
        $user = $request->user();

        $organizations = Organization::query()
            ->select(['id', 'name_en', 'name_am'])
            ->when(! $scope->isUnrestricted($user), fn ($query) => $query->whereIn('id', $scope->accessibleOrganizationIds($user)))
            ->orderBy('name_en')
            ->get();
        $units = $filters['organization_id']
            ? OrganizationUnit::query()
                ->where('organization_id', $filters['organization_id'])
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am', 'parent_unit_id'])
            : collect();

        $query = $selection->query($user, $filters);

        return Inertia::render('IdCards/BulkPrint', [
            'filters' => $filters,
            'organizations' => $organizations,
            'units' => $units,
            'total' => (clone $query)->count(),
            'cards' => $query
                ->with([
                    'employee:id,employee_number,full_name,name_en,current_assignment_id',
                    'employee.currentAssignment:id,organization_id,organization_unit_id',
                    'employee.currentAssignment.organization:id,name_en,name_am',
                    'employee.currentAssignment.organizationUnit:id,name_en,name_am',
                ])
                ->orderBy('created_at')
                ->paginate(50, ['id', 'card_number', 'employee_id', 'status', 'reprint_required', 'created_at'])
                ->withQueryString()
                ->through(fn (IdCard $card): array => [
                    'id' => $card->id,
                    'card_number' => $card->card_number,
                    'status' => $card->status->value,
                    'reprint_required' => (bool) $card->reprint_required,
                    // Display fields only (SEC-9).
                    'employee_number' => $card->employee?->employee_number,
                    'employee_name' => $card->employee?->full_name,
                    'organization' => $card->employee?->currentAssignment?->organization?->name_en,
                    'unit' => $card->employee?->currentAssignment?->organizationUnit?->name_en,
                ]),
            'maxCards' => CreatePrintBatchAction::MAX_CARDS,
        ]);
    }

    /**
     * Batch either the ticked cards or everything the filters match (capped),
     * then open the batch at the print station.
     */
    public function store(Request $request, IdCardPrintSelection $selection, CreatePrintBatchAction $create): RedirectResponse
    {
        $this->authorize('create', CardPrintBatch::class);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['selected', 'matching'])],
            'card_ids' => ['required_if:mode,selected', 'array', 'max:'.CreatePrintBatchAction::MAX_CARDS],
            'card_ids.*' => ['uuid'],
        ]);
        $query = $selection->query($request->user(), $this->filters($request));
        // Either way, only cards the selection allows: an id outside the
        // actor's scope or already queued never reaches a batch.
        $ids = $data['mode'] === 'selected'
            ? $query->whereIn('id', $data['card_ids'] ?? [])->pluck('id')->all()
            : $query->orderBy('created_at')->limit(CreatePrintBatchAction::MAX_CARDS)->pluck('id')->all();
        if ($data['mode'] === 'selected' && count($ids) !== count(array_unique($data['card_ids'] ?? []))) {
            throw ValidationException::withMessages(['card_ids' => __('id-cards.bulk_print_selection_changed')]);
        }
        if ($ids === []) {
            throw ValidationException::withMessages(['card_ids' => __('id-cards.bulk_print_nothing')]);
        }

        try {
            $batch = $create->execute($ids, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['card_ids' => $exception->getMessage()]);
        }

        return to_route('print-batches.station', $batch)->with('success', __('id-cards.print_batch_created'));
    }

    /** @return array{organization_id: ?string, organization_unit_id: ?string, include_sub_units: bool, need: string, search: string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'organization_unit_id' => ['nullable', 'uuid'],
            'include_sub_units' => ['nullable', 'boolean'],
            'need' => ['nullable', Rule::in(IdCardPrintSelection::NEEDS)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return [
            'organization_id' => $data['organization_id'] ?? null,
            // A unit only means something inside its organization.
            'organization_unit_id' => ($data['organization_id'] ?? null) ? ($data['organization_unit_id'] ?? null) : null,
            'include_sub_units' => (bool) ($data['include_sub_units'] ?? true),
            'need' => $data['need'] ?? 'pending_print',
            'search' => trim((string) ($data['search'] ?? '')),
        ];
    }
}
