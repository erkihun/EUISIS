<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Settlement;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaSettlementStatus;
use App\Enums\CafeteriaTransactionStatus;
use App\Models\CafeteriaSettlement;
use App\Models\CafeteriaTransaction;
use App\Models\Provider;
use App\Models\User;
use App\Services\Cafeteria\Policy\CafeteriaPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Provider settlements (docs/cafeteria-settlement-rules.md).
 *
 *   payee          = the provider that delivered (transaction.provider_id)
 *   billing owner  = the employee organization (transaction.employee_organization_id)
 *   service place  = the cafeteria (transaction.cafeteria_provider_id)
 *
 * Every amount is the transaction's applied snapshot, never a policy lookup,
 * so a later policy change cannot move a settled total. A draft links its
 * transactions (no double settlement); finalizing freezes them.
 */
class CafeteriaSettlementService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /**
     * Unsettled accepted transactions of the provider, grouped by employee
     * organization and cafeteria.
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function preview(Provider $provider, Carbon $from, Carbon $to): array
    {
        return $this->summarize($this->unsettled($provider, $from, $to));
    }

    public function createDraft(Provider $provider, Carbon $from, Carbon $to, User $actor, ?string $notes = null, ?Request $request = null): CafeteriaSettlement
    {
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['period_end' => __('cafeteria-policy.validation.settlement_period')]);
        }

        return DB::transaction(function () use ($provider, $from, $to, $actor, $notes, $request): CafeteriaSettlement {
            $settlement = CafeteriaSettlement::query()->create([
                'settlement_number' => 'SET-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'provider_id' => $provider->id,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'status' => CafeteriaSettlementStatus::Draft->value,
                'currency_code' => 'ETB',
                'notes' => $notes,
                'generated_by' => $actor->id,
                'generated_at' => now(),
            ]);

            // Claim the period's unsettled rows in ONE statement (risk SCL-5):
            // never load or bind every transaction id in PHP. The UPDATE locks
            // each row it changes, and PostgreSQL re-checks the IS NULL
            // condition after waiting, so a parallel draft cannot take them too.
            $claimed = $this->unsettled($provider, $from, $to)->update(['cafeteria_settlement_id' => $settlement->id]);
            if ($claimed === 0) {
                throw ValidationException::withMessages(['period_start' => __('cafeteria-policy.validation.settlement_empty')]);
            }

            $summary = $this->summarize(CafeteriaTransaction::query()->where('cafeteria_settlement_id', $settlement->id));
            $settlement->forceFill(['currency_code' => $summary['totals']['currency_code']])->save();
            $this->storeSummary($settlement, $summary);

            $this->audit->execute(AuditEventType::CafeteriaSettlementCreated, $actor, $settlement, null,
                newValues: ['provider_id' => $provider->id, 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), ...$summary['totals']],
                request: $request);

            return $settlement->fresh('lines');
        });
    }

    /** Freezes the draft; a transaction reversed meanwhile is released first. */
    public function finalize(CafeteriaSettlement $settlement, User $actor, ?Request $request = null): CafeteriaSettlement
    {
        return DB::transaction(function () use ($settlement, $actor, $request): CafeteriaSettlement {
            $settlement = CafeteriaSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->status !== CafeteriaSettlementStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.settlement_not_draft')]);
            }
            // Decision D-6: money leaves only on a second person's word, as for
            // policies, assessment submissions and restores. No override.
            if ($settlement->generated_by !== null && (int) $settlement->generated_by === (int) $actor->id) {
                throw ValidationException::withMessages(['finalized_by' => __('cafeteria-policy.validation.settlement_same_person')]);
            }

            CafeteriaTransaction::query()
                ->where('cafeteria_settlement_id', $settlement->id)
                ->where('status', '!=', CafeteriaTransactionStatus::Accepted->value)
                ->update(['cafeteria_settlement_id' => null]);

            $this->storeSummary($settlement, $this->summarize(
                CafeteriaTransaction::query()->where('cafeteria_settlement_id', $settlement->id),
            ));

            $settlement->forceFill([
                'status' => CafeteriaSettlementStatus::Finalized->value,
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
            ])->save();

            $this->audit->execute(AuditEventType::CafeteriaSettlementFinalized, $actor, $settlement, null,
                newValues: $settlement->only(['settlement_number', 'transaction_count', 'total_subsidy_amount', 'total_employee_amount', 'total_provider_amount']),
                request: $request);

            return $settlement->fresh('lines');
        });
    }

    public function cancel(CafeteriaSettlement $settlement, User $actor, ?Request $request = null): CafeteriaSettlement
    {
        return DB::transaction(function () use ($settlement, $actor, $request): CafeteriaSettlement {
            $settlement = CafeteriaSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->status !== CafeteriaSettlementStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.settlement_not_draft')]);
            }

            CafeteriaTransaction::query()->where('cafeteria_settlement_id', $settlement->id)->update(['cafeteria_settlement_id' => null]);
            $settlement->forceFill([
                'status' => CafeteriaSettlementStatus::Cancelled->value,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ])->save();

            $this->audit->execute(AuditEventType::CafeteriaSettlementCancelled, $actor, $settlement, null,
                newValues: ['settlement_number' => $settlement->settlement_number], request: $request);

            return $settlement;
        });
    }

    /** @return Builder<CafeteriaTransaction> */
    private function unsettled(Provider $provider, Carbon $from, Carbon $to): Builder
    {
        return CafeteriaTransaction::query()
            ->where('provider_id', $provider->id)
            ->where('status', CafeteriaTransactionStatus::Accepted->value)
            ->whereNull('cafeteria_settlement_id')
            // transaction_date is a DATE column: plain range bounds keep the index usable.
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<=', $to->toDateString());
    }

    /**
     * Lines and totals aggregated by the database (risk SCL-5). Each row is
     * converted to integer cents before summing, so totals are exact on every
     * driver and identical to summing the stored snapshots one by one.
     *
     * @param  Builder<CafeteriaTransaction>  $transactions
     * @return array{lines: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function summarize(Builder $transactions): array
    {
        $cents = fn (string $column): string => "SUM(CAST(ROUND(COALESCE({$column}, 0) * 100) AS INTEGER))";

        $lines = (clone $transactions)->toBase()
            ->reorder()
            ->groupBy('employee_organization_id', 'cafeteria_provider_id')
            ->select('employee_organization_id', 'cafeteria_provider_id')
            ->selectRaw('COUNT(*) AS transaction_count')
            ->selectRaw($cents('subsidy_amount_applied').' AS subsidy_cents')
            ->selectRaw($cents('employee_payable_amount').' AS employee_cents')
            ->get()
            ->map(fn ($row): array => [
                'employee_organization_id' => $row->employee_organization_id,
                'cafeteria_id' => $row->cafeteria_provider_id,
                'transaction_count' => (int) $row->transaction_count,
                'subsidy_cents' => (int) $row->subsidy_cents,
                'employee_cents' => (int) $row->employee_cents,
            ])
            ->sortBy(fn (array $line): string => ($line['employee_organization_id'] ?? '~').'|'.$line['cafeteria_id'])
            ->values();

        $currency = (clone $transactions)->toBase()->reorder()->whereNotNull('currency_code')->value('currency_code');

        return [
            'lines' => $lines->map(fn (array $line): array => [
                'employee_organization_id' => $line['employee_organization_id'],
                'cafeteria_id' => $line['cafeteria_id'],
                'transaction_count' => $line['transaction_count'],
                'subsidy_amount' => CafeteriaPricing::format($line['subsidy_cents']),
                'employee_amount' => CafeteriaPricing::format($line['employee_cents']),
                'provider_amount' => CafeteriaPricing::format($line['subsidy_cents'] + $line['employee_cents']),
            ])->all(),
            'totals' => [
                'transaction_count' => (int) $lines->sum('transaction_count'),
                'total_subsidy_amount' => CafeteriaPricing::format($lines->sum('subsidy_cents')),
                'total_employee_amount' => CafeteriaPricing::format($lines->sum('employee_cents')),
                'total_provider_amount' => CafeteriaPricing::format($lines->sum('subsidy_cents') + $lines->sum('employee_cents')),
                'currency_code' => $currency ?? 'ETB',
            ],
        ];
    }

    /** @param array{lines: list<array<string, mixed>>, totals: array<string, mixed>} $summary */
    private function storeSummary(CafeteriaSettlement $settlement, array $summary): void
    {
        $settlement->lines()->delete();
        foreach ($summary['lines'] as $line) {
            $settlement->lines()->create($line);
        }

        $settlement->forceFill([
            'transaction_count' => $summary['totals']['transaction_count'],
            'total_subsidy_amount' => $summary['totals']['total_subsidy_amount'],
            'total_employee_amount' => $summary['totals']['total_employee_amount'],
            'total_provider_amount' => $summary['totals']['total_provider_amount'],
        ])->save();
    }
}
