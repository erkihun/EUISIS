<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdCard;
use App\Services\AuditWriter;
use App\Services\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marks every card past its expiry date as expired, one audited change per
 * card. Verification already refuses such a card (IdCard::invalidReason); this
 * makes the status say so too, so lists, reports and renewals agree. A card
 * stays current: renewal replaces it. Safe to run any number of times.
 */
class ExpireCards extends Command
{
    protected $signature = 'core:expire-cards {--dry-run : Count only}';

    protected $description = 'Mark cards past their expiry date as expired';

    private const EXPIRABLE = [IdCard::PENDING_PRINT, IdCard::PRINTED, IdCard::ISSUED, IdCard::ACTIVE, IdCard::SUSPENDED];

    public function handle(AuditWriter $audit): int
    {
        $due = IdCard::query()->whereIn('status', self::EXPIRABLE)->whereNotNull('expires_at')->where('expires_at', '<=', now());
        if ($this->option('dry-run')) {
            $this->info((clone $due)->count().' card(s) are past their expiry date.');

            return self::SUCCESS;
        }

        $expired = 0;
        $due->with('employee.currentAssignment:id,organization_id')->chunkById(500, function ($cards) use ($audit, &$expired): void {
            foreach ($cards as $card) {
                DB::transaction(function () use ($card, $audit, &$expired): void {
                    $locked = IdCard::query()->lockForUpdate()->find($card->id);
                    if ($locked === null || ! in_array($locked->status, self::EXPIRABLE, true) || $locked->expires_at?->isFuture()) {
                        return;
                    }
                    $before = $locked->status;
                    $locked->forceFill(['status' => IdCard::EXPIRED])->save();
                    $audit->write('card.expired', null, $locked, $card->employee?->currentAssignment?->organization_id,
                        ['status' => $before], ['status' => IdCard::EXPIRED], 'Expiry date reached');
                    $expired++;
                });
            }
        });
        $this->info("{$expired} card(s) marked expired.");
        SystemHealth::recordRun('core:expire-cards');

        return self::SUCCESS;
    }
}
