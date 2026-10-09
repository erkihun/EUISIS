<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use App\Http\Resources\ProviderPortal\CafeteriaProviderLedgerEntryResource;
use App\Models\CafeteriaProviderLedgerEntry;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderLedgerController extends Controller
{
    use FormatsProviderPortalData;

    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        $entries = CafeteriaProviderLedgerEntry::query()
            ->with('transaction')
            ->where('cafeteria_provider_id', $provider->id)
            ->when($request->string('date_from')->toString(), fn ($query, string $date) => $query->whereDate('entry_date', '>=', $date))
            ->when($request->string('date_to')->toString(), fn ($query, string $date) => $query->whereDate('entry_date', '<=', $date))
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Cafeteria/Portal/Ledger/Index', [
            ...$this->portalPayload($request, $context, $provider),
            'entries' => CafeteriaProviderLedgerEntryResource::collection($entries)->resolve(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
            'filters' => $request->only(['date_from', 'date_to']),
        ]);
    }
}
