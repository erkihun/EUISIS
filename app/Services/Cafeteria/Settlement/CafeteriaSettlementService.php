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
use Illuminate\Support\Collection;
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
        return $this->summarize($this->unsettled($provider, $from, $to)->get());
    }

    public function createDraft(Provider $provider, Carbon $from, Carbon $to, User $actor, ?string $notes = null, ?Request $request = null): CafeteriaSettlement
    {
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['period_end' => __('cafeteria-policy.validation.settlement_period')]);
        }

        return DB::transaction(function () use ($provider, $from, $to, $actor, $notes, $request): CafeteriaSettlement {
            // Lock the rows being settled so a parallel draft cannot take them too.
            $transactions = $this->unsettled($provider, $from, $to)->lockForUpdate()->get();
            if ($transactions->isEmpty()) {
                throw ValidationException::withMessages(['period_start' => __('cafeteria-policy.validation.settlement_empty')]);
            }

            $summary = $this->summarize($transactions);
            $settlement = CafeteriaSettlement::query()->create([
                'settlement_number' => 'SET-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'provider_id' => $provider->id,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'status' => CafeteriaSettlementStatus::Draft->value,
                'currency_code' => $summary['totals']['currency_code'],
                'notes' => $notes,
                'generated_by' => $actor->id,
                'generated_at' => now(),
            ]);

            CafeteriaTransaction::query()->whereKey($transactions->modelKeys())->update(['cafeteria_settlement_id' => $settlement->id]);
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

            CafeteriaTransaction::query()
                ->where('cafeteria_settlement_id', $settlement->id)
                ->where('status', '!=', CafeteriaTransactionStatus::Accepted->value)
                ->update(['cafeteria_settlement_id' => null]);

            $this->storeSummary($settlement, $this->summarize(
                CafeteriaTransaction::query()->where('cafeteria_settlement_id', $settlement->id)->get(),
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
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString());
    }

    /**
     * @param  Collection<int, CafeteriaTransaction>  $transactions
     * @return array{lines: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function summarize(Collection $transactions): array
    {
        $lines = $transactions
            ->groupBy(fn (CafeteriaTransaction $t): string => ($t->employee_organization_id ?? '').'|'.$t->cafeteria_provider_id)
            ->map(function (Collection $group): array {
                /** @var CafeteriaTransaction $first */
                $first = $group->first();
                $subsidy = $group->sum(fn (CafeteriaTransaction $t): int => CafeteriaPricing::toCents((string) $t->subsidy_amount_applied));
                $employee = $group->sum(fn (CafeteriaTransaction $t): int => CafeteriaPricing::toCents((string) $t->employee_payable_amount));

                return [
                    'employee_organization_id' => $first->employee_organization_id,
                    'cafeteria_id' => $first->cafeteria_provider_id,
                    'transaction_count' => $group->count(),
                    'subsidy_cents' => $subsidy,
                    'employee_cents' => $employee,
                ];
            })
            ->sortBy(fn (array $line): string => ($line['employee_organization_id'] ?? '~').'|'.$line['cafeteria_id'])
            ->values();

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
                'transaction_count' => $transactions->count(),
                'total_subsidy_amount' => CafeteriaPricing::format($lines->sum('subsidy_cents')),
                'total_employee_amount' => CafeteriaPricing::format($lines->sum('employee_cents')),
                'total_provider_amount' => CafeteriaPricing::format($lines->sum('subsidy_cents') + $lines->sum('employee_cents')),
                'currency_code' => $transactions->pluck('currency_code')->filter()->first() ?? 'ETB',
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
