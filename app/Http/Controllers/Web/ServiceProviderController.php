<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceProviderRequest;
use App\Http\Requests\UpdateServiceProviderRequest;
use App\Models\Organization;
use App\Models\ServiceProvider;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ServiceProviderController extends Controller
{
    private const STATUSES = ['active', 'inactive', 'suspended'];

    /** The window the activity figures on these screens cover. */
    private const ACTIVITY_DAYS = 30;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ServiceProvider::class);

        $filters = [
            'search' => trim($request->string('search')->limit(100, '')->toString()),
            'service_type_id' => Str::isUuid((string) $request->input('service_type_id')) ? (string) $request->input('service_type_id') : null,
            'status' => in_array($request->input('status'), self::STATUSES, true) ? (string) $request->input('status') : null,
        ];

        $since = now()->subDays(self::ACTIVITY_DAYS);

        // Search and type narrow both the list and the status tab counts; status narrows only the list.
        $matching = fn (): Builder => ServiceProvider::query()
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                ->whereLike('name', "%{$filters['search']}%", caseSensitive: false)
                ->orWhereLike('code', "%{$filters['search']}%", caseSensitive: false)))
            ->when($filters['service_type_id'], fn (Builder $query, string $id) => $query->where('service_type_id', $id));

        $providers = $matching()
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->with(['serviceType:id,code,name_en,name_am', 'organization:id,name_en,name_am'])
            ->withCount(['transactions as transactions_recent' => fn (Builder $query) => $query->where('occurred_at', '>=', $since)])
            ->withMax('transactions as last_transaction_at', 'occurred_at')
            ->withCasts(['last_transaction_at' => 'datetime'])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $recent = ServiceTransaction::query()
            ->where('occurred_at', '>=', $since)
            ->toBase()
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS denied_count', [TransactionStatus::Denied->value])
            ->first();

        $serviceTypes = ServiceType::query()
            ->withCount('providers')
            ->orderBy('name_en')
            ->get()
            ->map(fn (ServiceType $type): array => [
                'id' => $type->id,
                'code' => $type->code,
                'name_en' => $type->name_en,
                'name_am' => $type->name_am,
                'providers_count' => (int) $type->providers_count,
            ]);

        return Inertia::render('ServiceProviders/Index', [
            'providers' => $providers,
            'filters' => $filters,
            'statusCounts' => $this->statusCounts($matching()),
            'stats' => [
                'providers' => $this->statusCounts(ServiceProvider::query()),
                'service_types_in_use' => $serviceTypes->where('providers_count', '>', 0)->count(),
                'transactions_recent' => (int) ($recent->total_count ?? 0),
                'denied_recent' => (int) ($recent->denied_count ?? 0),
                'days' => self::ACTIVITY_DAYS,
            ],
            'serviceTypes' => $serviceTypes,
            'transactions' => ServiceTransaction::query()
                ->with(['serviceProvider:id,name,code', 'serviceType:id,code,name_en,name_am'])
                ->orderByDesc('occurred_at')
                ->limit(8)
                ->get(),
            'can' => [
                'create' => $request->user()?->can('create', ServiceProvider::class) ?? false,
                'viewServiceTypes' => $request->user()?->can('viewAny', ServiceType::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ServiceProvider::class);

        return Inertia::render('ServiceProviders/Create', $this->formOptions());
    }

    public function store(StoreServiceProviderRequest $request): RedirectResponse
    {
        $provider = ServiceProvider::query()->create($request->validated());

        return to_route('service-providers.show', $provider)
            ->with('flash', ['message' => __('providers.created'), 'type' => 'success']);
    }

    public function show(Request $request, ServiceProvider $serviceProvider): Response
    {
        $this->authorize('view', $serviceProvider);

        $serviceProvider->load(['serviceType:id,code,name_en,name_am', 'organization:id,name_en,name_am']);

        $recent = $serviceProvider->transactions()
            ->where('occurred_at', '>=', now()->subDays(self::ACTIVITY_DAYS))
            ->toBase()
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS denied_count', [TransactionStatus::Denied->value])
            // Only money that actually moved: denied, reversed and unsynced entries are left out.
            ->selectRaw(
                'SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END) AS processed_amount',
                [TransactionStatus::Settled->value, TransactionStatus::Authorized->value],
            )
            ->first();

        $lastActivity = $serviceProvider->transactions()->max('occurred_at');

        return Inertia::render('ServiceProviders/Show', [
            'provider' => $serviceProvider,
            'transactions' => $serviceProvider->transactions()
                ->with('serviceType:id,code,name_en,name_am')
                ->orderByDesc('occurred_at')
                ->limit(100)
                ->get(),
            'stats' => [
                'transactions_recent' => (int) ($recent->total_count ?? 0),
                'denied_recent' => (int) ($recent->denied_count ?? 0),
                'processed_recent' => round((float) ($recent->processed_amount ?? 0), 2),
                'last_activity_at' => $lastActivity === null ? null : Carbon::parse($lastActivity)->toIso8601String(),
                'days' => self::ACTIVITY_DAYS,
            ],
            'can' => [
                'update' => $request->user()?->can('update', $serviceProvider) ?? false,
                'delete' => $request->user()?->can('delete', $serviceProvider) ?? false,
            ],
        ]);
    }

    public function edit(ServiceProvider $serviceProvider): Response
    {
        $this->authorize('update', $serviceProvider);

        return Inertia::render('ServiceProviders/Edit', [
            'provider' => $serviceProvider->load(['serviceType:id,code,name_en,name_am', 'organization:id,name_en,name_am']),
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateServiceProviderRequest $request, ServiceProvider $serviceProvider): RedirectResponse
    {
        $serviceProvider->update($request->validated());

        return to_route('service-providers.show', $serviceProvider)
            ->with('flash', ['message' => __('providers.updated'), 'type' => 'success']);
    }

    public function destroy(Request $request, ServiceProvider $serviceProvider): RedirectResponse
    {
        $this->authorize('delete', $serviceProvider);

        $serviceProvider->delete();

        return to_route('service-providers.index')
            ->with('flash', ['message' => __('providers.deleted'), 'type' => 'success']);
    }

    /**
     * Provider count per status, plus the total.
     *
     * @param  Builder<ServiceProvider>  $query
     * @return array<string, int>
     */
    private function statusCounts(Builder $query): array
    {
        $counts = $query
            ->select('status', DB::raw('COUNT(*) AS total_count'))
            ->groupBy('status')
            ->pluck('total_count', 'status');

        $result = ['all' => (int) $counts->sum()];

        foreach (self::STATUSES as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'serviceTypes' => ServiceType::query()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am']),
            'organizations' => Organization::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
        ];
    }
}
