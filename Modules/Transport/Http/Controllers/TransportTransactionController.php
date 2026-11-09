<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\OrganizationScope\OrganizationScopeService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Transport\Application\Services\Boarding\TransportBoardingService;
use Modules\Transport\Application\Services\TransportExportService;
use Modules\Transport\Application\Services\TransportReportService;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Http\Requests\TransportReportRequest;
use Modules\Transport\Http\Resources\TransportTransactionResource;

/** Boardings as staff see them: within the viewer's organization scope, exported in the viewer's language, voidable with a reason. */
class TransportTransactionController extends Controller
{
    public function index(TransportReportRequest $request, TransportReportService $reports): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-transactions.viewAny'), 403);
        $filters = $reports->normaliseFilters($request->validated());

        $transactions = $reports->forAdmin($user, $filters)
            ->with(['employee.currentAssignment.organization', 'provider', 'route', 'trip', 'vehicle'])
            ->latest('scanned_at')->paginate(25)->withQueryString();

        return Inertia::render('Transport/Transactions/Index', [
            'transactions' => TransportTransactionResource::collection($transactions)->response()->getData(true),
            'filters' => $filters,
            'providers' => app(ProviderDirectory::class)->offering('transport'),
            'can' => ['export' => $user->can('transport-transactions.export'), 'void' => $user->can('transport-transactions.void')],
        ]);
    }

    public function show(Request $request, TransportTransaction $transaction, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-transactions.view'), 403);
        abort_unless($scope->canAccessEmployee($user, $transaction->employee()->with('currentAssignment')->firstOrFail()), 403);

        return Inertia::render('Transport/Transactions/Show', [
            'transaction' => (new TransportTransactionResource($transaction->load(['employee.currentAssignment.organization', 'provider', 'route', 'trip', 'vehicle', 'device', 'stop', 'pass', 'scannedBy', 'settlement'])))->resolve(),
            'can' => ['void' => $user->can('transport-transactions.void') && $transaction->voided_at === null && $transaction->transport_settlement_id === null],
        ]);
    }

    public function export(TransportReportRequest $request, TransportReportService $reports, TransportExportService $exports)
    {
        $user = $request->user();
        abort_unless($user?->can('transport-transactions.export'), 403);
        $filters = $reports->normaliseFilters($request->validated());

        return $exports->boardingsCsv($reports->forAdmin($user, $filters)->orderBy('transport_transactions.id'), $exports->locale($request), staffTier: true, filename: 'transport-boardings-'.$filters['date_from'].'-'.$filters['date_to'].'.csv');
    }

    public function void(Request $request, TransportTransaction $transaction, TransportBoardingService $engine, OrganizationScopeService $scope): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-transactions.void'), 403);
        abort_unless($scope->canAccessEmployee($request->user(), $transaction->employee()->with('currentAssignment')->firstOrFail()), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        try {
            $engine->void($transaction, $request->user(), $data['reason'], $request);
        } catch (DomainException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.boarding_voided')]);
    }
}
