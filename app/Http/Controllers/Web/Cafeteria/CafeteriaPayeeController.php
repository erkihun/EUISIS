<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cafeteria;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Cafeteria\Concerns\CafeteriaAdminContext;
use App\Models\Provider;
use App\Services\Cafeteria\Network\CafeteriaProviderRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cafeteria Management → Providers: the payees that operate cafeterias.
 * A provider's settings never set an organization's subsidy.
 */
class CafeteriaPayeeController extends Controller
{
    use CafeteriaAdminContext;

    public function __construct(private readonly CafeteriaProviderRegistry $registry) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $providers = Provider::query()
            ->whereHas('providerType', fn (Builder $q) => $q->where('code', 'CAFETERIA'))
            ->withCount(['cafeteriaNetworks as network_count', 'cafeterias as cafeteria_count'])
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('provider_code', ci_like_operator(), "%{$search}%")
                ->orWhere('name_en', ci_like_operator(), "%{$search}%")
                ->orWhere('name_am', ci_like_operator(), "%{$search}%")))
            ->orderBy('name_en')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Cafeteria/Payees/Index', [
            'rows' => collect($providers->items())->map(fn (Provider $p): array => [
                'id' => $p->id,
                'code' => $p->provider_code,
                'name_en' => $p->name_en,
                'name_am' => $p->name_am,
                'contact_person' => $p->contact_person,
                'email' => $p->email,
                'status' => $p->status,
                'network_count' => (int) $p->network_count,
                'cafeteria_count' => (int) $p->cafeteria_count,
            ])->all(),
            'meta' => ['currentPage' => $providers->currentPage(), 'lastPage' => $providers->lastPage(), 'total' => $providers->total(), 'perPage' => $providers->perPage()],
            'filters' => $request->only(['search']),
            'can' => ['manage' => $request->user()->can('cafeteria_providers.create')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Cafeteria/Payees/Form', ['provider' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->registry->create($this->validated($request), $request->user());

        return to_route('cafeteria.payees.index')->with('flash', ['message' => __('cafeteria.providerCreated'), 'type' => 'success']);
    }

    public function edit(Provider $payee): Response
    {
        return Inertia::render('Cafeteria/Payees/Form', [
            'provider' => [
                'id' => $payee->id,
                'provider_code' => $payee->provider_code,
                'name_en' => $payee->name_en,
                'name_am' => $payee->name_am,
                'contact_person' => $payee->contact_person,
                'phone_number' => $payee->phone_number,
                'email' => $payee->email,
                'address' => $payee->address,
                'status' => $payee->status,
                'settlement_details' => $payee->metadata['settlement_details'] ?? null,
            ],
        ]);
    }

    public function update(Request $request, Provider $payee): RedirectResponse
    {
        $this->registry->update($payee, $this->validated($request, $payee), $request->user());

        return to_route('cafeteria.payees.index')->with('flash', ['message' => __('cafeteria.providerUpdated'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Provider $provider = null): array
    {
        return $request->validate([
            'provider_code' => [$provider ? 'prohibited' : 'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('providers', 'provider_code')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'settlement_details' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
