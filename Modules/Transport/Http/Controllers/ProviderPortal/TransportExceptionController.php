<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Domain\Enums\TransportExceptionType;
use Modules\Transport\Domain\Models\TransportException;
use Modules\Transport\Http\Resources\TransportExceptionResource;

/** The provider's own operational exceptions, read-only: review is the city's. */
class TransportExceptionController extends Controller
{
    public function index(Request $request, ProviderPortalSession $session): Response
    {
        $providerId = $session->providerId($request);
        abort_if($providerId === null || ! $session->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        $exceptions = TransportException::query()->with(['trip', 'employee', 'vehicle', 'device'])->where('provider_id', $providerId)
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->status ?: 'open', fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->when($request->date_from, fn ($q, $d) => $q->whereDate('occurred_at', '>=', $d))
            ->when($request->date_to, fn ($q, $d) => $q->whereDate('occurred_at', '<=', $d))
            ->latest('occurred_at')->paginate(25)->withQueryString();

        return Inertia::render('ProviderPortal/Transport/Exceptions/Index', [
            'exceptions' => TransportExceptionResource::collection($exceptions)->response()->getData(true),
            'filters' => ['status' => $request->status ?: 'open'] + $request->only('type', 'date_from', 'date_to'),
            'types' => TransportExceptionType::values(),
        ]);
    }
}
