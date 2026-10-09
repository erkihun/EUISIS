<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cafeteria;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Cafeteria\Concerns\CafeteriaAdminContext;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaSettlement;
use App\Models\CafeteriaSettlementLine;
use App\Models\Organization;
use App\Models\Provider;
use App\Services\Cafeteria\Policy\CafeteriaPricing;
use App\Services\Cafeteria\Settlement\CafeteriaSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Cafeteria Management → Settlements (provider payables by employee organization). */
class CafeteriaSettlementController extends Controller
{
    use CafeteriaAdminContext;

    public function __construct(private readonly CafeteriaSettlementService $settlements) {}

    public function index(Request $request): Response
    {
        $rows = CafeteriaSettlement::query()
            ->with('provider:id,provider_code,name_en,name_am')
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->string('provider_id')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('period_end')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Cafeteria/Settlements/Index', [
            'rows' => collect($rows->items())->map(fn (CafeteriaSettlement $s): array => $this->summary($s))->all(),
            'meta' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total(), 'perPage' => $rows->perPage()],
            'filters' => $request->only(['provider_id', 'status']),
            'providers' => $this->providerOptions(),
            'can' => ['manage' => $request->user()->can('cafeteria_settlements.manage')],
        ]);
    }

    public function create(Request $request): Response
    {
        $preview = null;
        if ($request->filled(['provider_id', 'period_start', 'period_end'])) {
            $data = $this->period($request);
            $provider = Provider::query()->findOrFail($data['provider_id']);
            $preview = $this->present($this->settlements->preview($provider, Carbon::parse($data['period_start']), Carbon::parse($data['period_end'])));
        }

        return Inertia::render('Cafeteria/Settlements/Create', [
            'providers' => $this->providerOptions(),
            'filters' => $request->only(['provider_id', 'period_start', 'period_end']),
            'preview' => $preview,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->period($request) + $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $settlement = $this->settlements->createDraft(
            Provider::query()->findOrFail($data['provider_id']),
            Carbon::parse($data['period_start']),
            Carbon::parse($data['period_end']),
            $request->user(),
            $data['notes'] ?? null,
            $request,
        );

        return to_route('cafeteria.settlements.show', $settlement)
            ->with('flash', ['message' => __('cafeteria-policy.settlement_created'), 'type' => 'success']);
    }

    public function show(Request $request, CafeteriaSettlement $settlement): Response
    {
        $settlement->load(['provider:id,provider_code,name_en,name_am', 'lines.employeeOrganization:id,code,name_en,name_am', 'lines.cafeteria:id,code,name_en,name_am,location_type']);

        return Inertia::render('Cafeteria/Settlements/Show', [
            'settlement' => [
                ...$this->summary($settlement),
                'notes' => $settlement->notes,
                'generated_at' => $settlement->generated_at?->toIso8601String(),
                'finalized_at' => $settlement->finalized_at?->toIso8601String(),
            ],
            'lines' => $settlement->lines->map(fn (CafeteriaSettlementLine $l): array => [
                'id' => $l->id,
                'employee_organization' => $this->namePair($l->employeeOrganization),
                'cafeteria' => $this->namePair($l->cafeteria) + ['location_type' => $l->cafeteria?->location_type?->value],
                'transaction_count' => $l->transaction_count,
                'subsidy_amount' => (string) $l->subsidy_amount,
                'employee_amount' => (string) $l->employee_amount,
                'provider_amount' => (string) $l->provider_amount,
            ])->values(),
            'byOrganization' => $this->liabilityByOrganization($settlement),
            'can' => ['manage' => $request->user()->can('cafeteria_settlements.manage')],
        ]);
    }

    public function finalize(Request $request, CafeteriaSettlement $settlement): RedirectResponse
    {
        $this->settlements->finalize($settlement, $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.settlement_finalized'), 'type' => 'success']);
    }

    public function cancel(Request $request, CafeteriaSettlement $settlement): RedirectResponse
    {
        $this->settlements->cancel($settlement, $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.settlement_cancelled'), 'type' => 'success']);
    }

    /** @return array<string, string> */
    private function period(Request $request): array
    {
        return $request->validate([
            'provider_id' => ['required', 'uuid', 'exists:providers,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(CafeteriaSettlement $s): array
    {
        return [
            'id' => $s->id,
            'settlement_number' => $s->settlement_number,
            'provider' => $this->namePair($s->provider, 'provider_code'),
            'period_start' => $s->period_start?->toDateString(),
            'period_end' => $s->period_end?->toDateString(),
            'status' => $s->status?->value,
            'transaction_count' => $s->transaction_count,
            'total_subsidy_amount' => (string) $s->total_subsidy_amount,
            'total_employee_amount' => (string) $s->total_employee_amount,
            'total_provider_amount' => (string) $s->total_provider_amount,
            'currency_code' => $s->currency_code,
        ];
    }

    /**
     * @param  array{lines: list<array<string, mixed>>, totals: array<string, mixed>}  $preview
     * @return array<string, mixed>
     */
    private function present(array $preview): array
    {
        $organizations = Organization::query()->whereIn('id', array_filter(array_column($preview['lines'], 'employee_organization_id')))->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');
        $cafeterias = CafeteriaProvider::query()->whereIn('id', array_column($preview['lines'], 'cafeteria_id'))->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');

        return [
            'lines' => array_map(fn (array $line): array => [
                ...$line,
                'employee_organization' => $this->namePair($organizations->get($line['employee_organization_id'])),
                'cafeteria' => $this->namePair($cafeterias->get($line['cafeteria_id'])),
            ], $preview['lines']),
            'totals' => $preview['totals'],
        ];
    }

    /** @return list<array<string, mixed>> the billing view: each organization's total across cafeterias */
    private function liabilityByOrganization(CafeteriaSettlement $settlement): array
    {
        return $settlement->lines
            ->groupBy(fn (CafeteriaSettlementLine $l): string => $l->employee_organization_id ?? '')
            ->map(fn ($lines): array => [
                'employee_organization' => $this->namePair($lines->first()->employeeOrganization),
                'transaction_count' => $lines->sum('transaction_count'),
                'subsidy_amount' => CafeteriaPricing::format($lines->sum(fn ($l) => CafeteriaPricing::toCents((string) $l->subsidy_amount))),
                'employee_amount' => CafeteriaPricing::format($lines->sum(fn ($l) => CafeteriaPricing::toCents((string) $l->employee_amount))),
                'provider_amount' => CafeteriaPricing::format($lines->sum(fn ($l) => CafeteriaPricing::toCents((string) $l->provider_amount))),
            ])
            ->values()
            ->all();
    }
}
