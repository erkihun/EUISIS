<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderLedgerEntry;
use App\Models\CafeteriaTransaction;
use App\Services\Cafeteria\Policy\CafeteriaPricing;
use Illuminate\Support\Facades\DB;

/**
 * The provider-portal ledger (risk register CON-9). One entry per
 * (transaction, entry type), posted under the provider row lock with a
 * running balance kept on the provider — O(1) per posting, never a sum over
 * the provider's whole history. Call inside the same transaction as the
 * change being posted, so the ledger cannot drift from the transactions.
 */
final class CafeteriaProviderLedgerService
{
    public const SCAN_CREDIT = 'scan_subsidy';

    public const REVERSAL_DEBIT = 'scan_reversal';

    public function credit(CafeteriaTransaction $transaction, ?int $actorId): ?CafeteriaProviderLedgerEntry
    {
        return $this->post($transaction, self::SCAN_CREDIT, debit: '0', credit: (string) $transaction->subsidy_amount_applied, actorId: $actorId);
    }

    /** Reverse a scan's credit; only when it was credited and not yet reversed. */
    public function reverse(CafeteriaTransaction $transaction, ?int $actorId): ?CafeteriaProviderLedgerEntry
    {
        $credited = CafeteriaProviderLedgerEntry::query()
            ->where('cafeteria_transaction_id', $transaction->id)
            ->where('entry_type', self::SCAN_CREDIT)
            ->value('credit');
        if ($credited === null) {
            return null;
        }

        return $this->post($transaction, self::REVERSAL_DEBIT, debit: (string) $credited, credit: '0', actorId: $actorId);
    }

    private function post(CafeteriaTransaction $transaction, string $type, string $debit, string $credit, ?int $actorId): ?CafeteriaProviderLedgerEntry
    {
        if ($transaction->cafeteria_provider_id === null) {
            return null;
        }

        return DB::transaction(function () use ($transaction, $type, $debit, $credit, $actorId): ?CafeteriaProviderLedgerEntry {
            /** @var CafeteriaProvider $provider */
            $provider = CafeteriaProvider::query()->whereKey($transaction->cafeteria_provider_id)->lockForUpdate()->firstOrFail();

            // Idempotent: a retried scan or a second reversal posts nothing.
            if (CafeteriaProviderLedgerEntry::query()->where('cafeteria_transaction_id', $transaction->id)->where('entry_type', $type)->exists()) {
                return null;
            }

            $balance = CafeteriaPricing::toCents((string) $provider->ledger_balance)
                + CafeteriaPricing::toCents($credit) - CafeteriaPricing::toCents($debit);
            $provider->forceFill(['ledger_balance' => CafeteriaPricing::format($balance)])->save();

            return CafeteriaProviderLedgerEntry::query()->create([
                'cafeteria_provider_id' => $provider->id,
                'cafeteria_transaction_id' => $transaction->id,
                'entry_date' => $transaction->transaction_date,
                'entry_type' => $type,
                'debit' => $debit,
                'credit' => $credit,
                'balance_after' => CafeteriaPricing::format($balance),
                'description' => $transaction->transaction_number,
                'created_by' => $actorId,
            ]);
        });
    }
}
