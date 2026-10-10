<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaSettlementStatus;
use App\Enums\CafeteriaTransactionStatus;
use App\Models\CafeteriaSettlement;
use App\Models\CafeteriaTransaction;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaLedgerService;
use App\Services\Cafeteria\CafeteriaProviderLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class ReverseCafeteriaTransactionAction
{
    public function __construct(
        private CafeteriaLedgerService $ledgerService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(CafeteriaTransaction $transaction, User $actor, ?string $reason = null, ?Request $request = null): CafeteriaTransaction
    {
        $transaction = DB::transaction(function () use ($transaction, $actor): CafeteriaTransaction {
            // Checked on the locked row, not the caller's copy: two reversals of
            // one meal (a double click, two supervisors) each passed a check made
            // before the lock and each refunded the subsidy.
            //
            // Lock order is settlement -> transaction, the same as
            // CafeteriaSettlementService::finalize(): finalize can no longer
            // freeze a settlement while one of its meals is being reversed
            // (the provider would be paid for a reversed meal), and the two
            // paths cannot deadlock (risk register CON-8).
            $settlementId = CafeteriaTransaction::query()->whereKey($transaction->getKey())->value('cafeteria_settlement_id');
            if ($settlementId !== null) {
                CafeteriaSettlement::query()->whereKey($settlementId)->lockForUpdate()->first();
            }
            $transaction = CafeteriaTransaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
            if ($transaction->cafeteria_settlement_id !== $settlementId) {
                throw ValidationException::withMessages(['transaction' => [__('cafeteria-policy.validation.settlement_not_draft')]]);
            }

            if ($transaction->isReversed()) {
                throw ValidationException::withMessages(['transaction' => [__('cafeteria.alreadyReversed')]]);
            }

            if (! $transaction->isAccepted()) {
                throw ValidationException::withMessages(['transaction' => [__('cafeteria.cannotReverseStatus')]]);
            }

            // A finalized settlement is final: reversing would change what was paid.
            if ($transaction->cafeteria_settlement_id !== null
                && $transaction->settlement()->where('status', CafeteriaSettlementStatus::Finalized->value)->exists()) {
                throw ValidationException::withMessages(['transaction' => [__('cafeteria-policy.validation.settlement_not_draft')]]);
            }

            $transaction->forceFill(['status' => CafeteriaTransactionStatus::Reversed])->save();

            // Clearing active_key frees the entitlement at every cafeteria again.
            $transaction->consumedDays()
                ->whereNull('reversed_at')
                ->update([
                    'reversed_at' => now(),
                    'reversed_by' => $actor->id,
                    'reversal_transaction_id' => $transaction->id,
                    'status' => 'reversed',
                    'active_key' => null,
                ]);

            // Write a reversal ledger entry to restore the ledger balance
            $reversalAmount = $transaction->is_extra_scan
                ? $transaction->deduction_amount   // refund the carry-forward deduction
                : $transaction->subsidy_amount_applied; // refund the subsidy net usage

            if ((float) $reversalAmount > 0) {
                $this->ledgerService->recordReversal(
                    $transaction->employee,
                    (float) $reversalAmount,
                    Carbon::parse($transaction->transaction_date),
                    $transaction,
                    $actor,
                );
            }

            // The provider-portal ledger is debited for a credited scan, in the
            // same transaction, so the provider balance follows reversals (CON-9).
            app(CafeteriaProviderLedgerService::class)->reverse($transaction, $actor->id);

            return $transaction;
        });

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaTransactionReversed,
            $actor,
            $transaction,
            $transaction->employee_organization_id ?? $transaction->provider?->organization_id,
            newValues: [
                'transaction_number' => $transaction->transaction_number,
                'employee_id' => $transaction->employee_id,
            ],
            reason: $reason,
            request: $request,
        );

        return $transaction;
    }
}
