<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CafeteriaGrantStatus;
use App\Enums\CafeteriaTransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\OrganizationCafeteriaAccess;
use App\Services\Cafeteria\CafeteriaSettingsService;
use App\Services\Cafeteria\Policy\CafeteriaPolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What EUISIS reads from the cafeteria platform (`/api/v1/integration/cafeteria`,
 * an API client with the `cafeteria:read` scope): an employee's cafeteria
 * entitlement for the employee portal, their recent meals, the cafeterias,
 * and a period's totals for the Situation Room. Money and policy come from
 * the cafeteria's own rules; EUISIS only displays them.
 */
class CafeteriaIntegrationController extends Controller
{
    public function __construct(
        private readonly CafeteriaPolicyResolver $policies,
        private readonly CafeteriaSettingsService $settings,
    ) {}

    /** The employee's standing in the cafeteria: the policy in force, today's use, any exclusion. */
    public function entitlement(Request $request, string $employee): JsonResponse
    {
        $found = $this->employee($employee);
        if ($found === null) {
            return self::notFound();
        }
        $today = Carbon::today();
        $organizationId = $found->currentAssignment?->organization_id;
        $exclusion = EmployeeCafeteriaExclusion::query()->where('employee_id', $found->id)->get()
            ->first(fn (EmployeeCafeteriaExclusion $e): bool => $e->isActiveOn($today));

        $access = $organizationId === null ? null : OrganizationCafeteriaAccess::query()->with(['primaryCafeteria', 'network.mainCafeteria'])
            ->where('organization_id', $organizationId)->where('status', CafeteriaGrantStatus::Active->value)->effectiveOn($today)->first();
        $cafeteria = $access?->primaryCafeteria ?? $access?->network?->mainCafeteria;
        $policy = null;
        $reason = null;
        if ($cafeteria !== null) {
            try {
                $resolution = $this->policies->resolve($found, $cafeteria, $today->copy()->setTime(12, 0));
                $policy = $resolution->resolved() ? $resolution->policy : null;
                $reason = $resolution->reason;
            } catch (Throwable $e) {
                $reason = 'policy_resolution_failed';
            }
        }
        $usedToday = CafeteriaTransaction::query()->where('employee_id', $found->id)
            ->whereDate('transaction_date', $today)->where('status', CafeteriaTransactionStatus::Accepted->value)->count();

        return response()->json(['data' => [
            'employee_id' => $found->id,
            'employee_number' => $found->employee_number,
            'organization_id' => $organizationId,
            'eligible' => $exclusion === null && $policy !== null,
            'reason' => match (true) {
                $exclusion !== null => 'excluded',
                $organizationId === null => 'no_placement',
                $cafeteria === null => 'no_cafeteria_access',
                $policy === null => $reason ?? 'no_policy',
                default => null,
            },
            'policy' => $policy === null ? null : [
                'id' => $policy->id,
                'version_no' => $policy->version_no,
                'daily_subsidy_amount' => self::money($policy->daily_subsidy_amount),
                'employee_contribution_amount' => self::money($policy->employee_contribution_amount),
                'provider_price' => self::money($policy->provider_price),
                'currency_code' => $policy->currency_code,
                'max_daily_uses' => (int) $policy->max_daily_uses,
                'effective_from' => $policy->effective_from?->toDateString(),
                'effective_to' => $policy->effective_to?->toDateString(),
            ],
            'cafeteria' => $cafeteria === null ? null : ['id' => $cafeteria->id, 'code' => $cafeteria->code, 'name_en' => $cafeteria->name_en, 'name_am' => $cafeteria->name_am],
            'used_today' => $usedToday,
            'exclusion' => $exclusion === null ? null : [
                'type' => $exclusion->exclusion_type instanceof \BackedEnum ? $exclusion->exclusion_type->value : $exclusion->exclusion_type,
                'starts_on' => $exclusion->starts_on?->toDateString(),
                'ends_on' => $exclusion->ends_on?->toDateString(),
            ],
            'as_of' => now()->toIso8601String(),
        ]]);
    }

    /** The employee's recent meals (newest first, up to 100). */
    public function transactions(Request $request, string $employee): JsonResponse
    {
        $found = $this->employee($employee);
        if ($found === null) {
            return self::notFound();
        }
        $rows = CafeteriaTransaction::query()->with('provider:id,code,name_en,name_am')
            ->where('employee_id', $found->id)->orderByDesc('scanned_at')->limit(min(100, max(1, (int) $request->integer('limit', 30))))->get();

        return response()->json(['data' => $rows->map(fn (CafeteriaTransaction $t): array => [
            'id' => $t->id,
            'transaction_number' => $t->transaction_number,
            'transaction_date' => $t->transaction_date instanceof Carbon ? $t->transaction_date->toDateString() : (string) $t->transaction_date,
            'scanned_at' => $t->scanned_at?->toIso8601String(),
            'status' => $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status,
            'subsidy_amount' => self::money($t->subsidy_amount_applied),
            'employee_payable_amount' => self::money($t->employee_payable_amount),
            'total_amount' => self::money($t->total_amount_applied ?? $t->meal_amount),
            'currency_code' => $t->currency_code,
            'is_extra_scan' => (bool) $t->is_extra_scan,
            'cafeteria' => $t->provider === null ? null : ['id' => $t->provider->id, 'code' => $t->provider->code, 'name_en' => $t->provider->name_en, 'name_am' => $t->provider->name_am],
        ])->values()->all()]);
    }

    /** The cafeterias (locations) the platform serves. */
    public function providers(Request $request): JsonResponse
    {
        $rows = CafeteriaProvider::query()->orderBy('name_en')->get();

        return response()->json(['data' => $rows->map(fn (CafeteriaProvider $c): array => [
            'id' => $c->id, 'code' => $c->code, 'name_en' => $c->name_en, 'name_am' => $c->name_am,
            'location_type' => $c->location_type instanceof \BackedEnum ? $c->location_type->value : $c->location_type,
            'operational_status' => $c->operational_status instanceof \BackedEnum ? $c->operational_status->value : $c->operational_status,
            'is_active' => (bool) $c->is_active, 'network_id' => $c->cafeteria_service_network_id, 'payee_id' => $c->provider_id,
        ])->values()->all()]);
    }

    /** A period's totals (today by default), for the Situation Room: scans, meals, subsidy, per cafeteria. */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : Carbon::today();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : $from->copy()->endOfDay();
        $accepted = CafeteriaTransactionStatus::Accepted->value;

        $base = CafeteriaTransaction::query()->whereBetween('scanned_at', [$from, $to]);
        $totals = (clone $base)->selectRaw(
            'count(*) as scans, sum(case when status = ? then 1 else 0 end) as meals, sum(case when status = ? then coalesce(subsidy_amount_applied, 0) else 0 end) as subsidy, sum(case when status = ? then coalesce(employee_payable_amount, 0) else 0 end) as employee_paid, count(distinct employee_id) as employees',
            [$accepted, $accepted, $accepted],
        )->first();
        $perCafeteria = (clone $base)->where('status', $accepted)
            ->select('cafeteria_provider_id', DB::raw('count(*) as meals'), DB::raw('sum(coalesce(subsidy_amount_applied, 0)) as subsidy'))
            ->groupBy('cafeteria_provider_id')->get()->keyBy('cafeteria_provider_id');
        $names = CafeteriaProvider::query()->whereIn('id', $perCafeteria->keys()->all())->get()->keyBy('id');

        return response()->json(['data' => [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'scans' => (int) ($totals->scans ?? 0), 'meals' => (int) ($totals->meals ?? 0), 'employees' => (int) ($totals->employees ?? 0),
            'subsidy_amount' => self::money($totals->subsidy ?? 0), 'employee_paid_amount' => self::money($totals->employee_paid ?? 0),
            'currency_code' => (string) $this->settings->get('currency', 'ETB'),
            'per_cafeteria' => $perCafeteria->map(fn ($row, string $id): array => [
                'id' => $id, 'code' => $names[$id]->code ?? null, 'name_en' => $names[$id]->name_en ?? null, 'name_am' => $names[$id]->name_am ?? null,
                'meals' => (int) $row->meals, 'subsidy_amount' => self::money($row->subsidy),
            ])->values()->all(),
            'as_of' => now()->toIso8601String(),
        ]]);
    }

    private function employee(string $id): ?Employee
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return Employee::query()->with('currentAssignment')->find($id);
    }

    /** Money as EUISIS prints it: two decimals, whatever the database driver returns. */
    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private static function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Employee not known to the cafeteria platform.', 'code' => 'NOT_FOUND', 'errors' => (object) []], 404);
    }
}
