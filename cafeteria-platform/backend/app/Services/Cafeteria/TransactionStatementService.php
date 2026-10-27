<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\CafeteriaTransactionStatus;
use App\Models\CafeteriaTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TransactionStatementService
{
    public function filters(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['all', 'daily', 'weekly', 'monthly', 'yearly'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'provider_id' => ['nullable', 'uuid', 'exists:cafeteria_providers,id'],
            'status' => ['nullable', Rule::enum(CafeteriaTransactionStatus::class)],
            'extra_only' => ['nullable', 'boolean'],
        ]);
        $period = $data['period'] ?? (! empty($data['date']) ? 'daily' : 'monthly');
        $date = Carbon::parse($data['date'] ?? now()->toDateString());
        [$start, $end] = match ($period) {
            'daily' => [$date->copy(), $date->copy()],
            'weekly' => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->endOfWeek(Carbon::SUNDAY)],
            'monthly' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            'yearly' => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
            default => [null, null],
        };

        return [
            'period' => $period, 'date' => $date->toDateString(),
            'start_date' => $start?->toDateString(), 'end_date' => $end?->toDateString(),
            'provider_id' => $data['provider_id'] ?? '', 'status' => $data['status'] ?? '',
            'extra_only' => $request->boolean('extra_only') ? '1' : '',
        ];
    }

    public function query(User $user, array $filters): Builder
    {
        $query = CafeteriaTransaction::query()
            ->when($filters['provider_id'], fn ($q, $id) => $q->where('cafeteria_provider_id', $id))
            ->when($filters['start_date'], fn ($q) => $q->whereBetween('transaction_date', [$filters['start_date'], $filters['end_date']]))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['extra_only'] === '1', fn ($q) => $q->where('is_extra_scan', true));

        $access = app(CafeteriaProviderAccessService::class);
        if (! $access->canAccessAllProviders($user)) {
            $query->whereIn('cafeteria_provider_id', $access->accessibleProviderIds($user));
        }

        return $query;
    }

    public function summary(Builder $query): array
    {
        $row = (clone $query)->withoutEagerLoads()->reorder()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'accepted' THEN meal_amount ELSE 0 END), 0) AS meals")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'accepted' THEN subsidy_amount_applied ELSE 0 END), 0) AS subsidy")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'accepted' THEN employee_payable_amount ELSE 0 END), 0) AS employee_payable")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'accepted' THEN deduction_amount ELSE 0 END), 0) AS deductions")
            ->first();

        return ['total' => (int) $row->total, 'accepted' => (int) $row->accepted,
            'meals' => (float) $row->meals, 'subsidy' => (float) $row->subsidy,
            'employee_payable' => (float) $row->employee_payable, 'deductions' => (float) $row->deductions];
    }
}
