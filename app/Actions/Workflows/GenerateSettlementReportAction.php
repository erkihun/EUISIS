<?php

declare(strict_types=1);

namespace App\Actions\Workflows;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Providers\Domain\Models\ServiceProvider;

/**
 * Settlement total for one provider over one period.
 *
 * F18 (master backbone audit): the period was echoed back but never applied,
 * so every month's report was the provider's all-time total. The period is now
 * a month (`2026-10`) or a day (`2026-10-21`) and bounds `occurred_at`.
 */
class GenerateSettlementReportAction
{
    /** @return array{provider_id: string, period: string, period_start: string, period_end: string, settlement_amount: float, transaction_count: int} */
    public function execute(ServiceProvider $provider, string $period): array
    {
        [$start, $end] = $this->bounds($period);

        $row = DB::table('service_transactions')
            ->where('service_provider_id', $provider->id)
            ->where('status', 'settled')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as transactions')
            ->first();

        return [
            'provider_id' => $provider->id,
            'period' => $period,
            'period_start' => $start->toDateString(),
            'period_end' => $end->copy()->subDay()->toDateString(),
            'settlement_amount' => (float) ($row->total ?? 0),
            'transaction_count' => (int) ($row->transactions ?? 0),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} half-open [start, end) */
    private function bounds(string $period): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $period, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $start = Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();

            return [$start, $start->copy()->addMonth()];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $period) === 1) {
            try {
                $start = Carbon::createFromFormat('Y-m-d', $period)->startOfDay();
            } catch (\Throwable) {
                throw new InvalidArgumentException('Invalid settlement period.');
            }
            if ($start->format('Y-m-d') !== $period) {
                throw new InvalidArgumentException('Invalid settlement period.');
            }

            return [$start, $start->copy()->addDay()];
        }

        throw new InvalidArgumentException('Invalid settlement period.');
    }
}
