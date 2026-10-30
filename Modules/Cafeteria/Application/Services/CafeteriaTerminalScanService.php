<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Services;

use App\Models\IdCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cafeteria\Contracts\CafeteriaTerminalScan;
use Modules\Cafeteria\Contracts\Data\CafeteriaScanOutcome;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;

/** The NFC terminal path into the QR scan rules (moved from Api\V1\NfcController). */
final class CafeteriaTerminalScanService implements CafeteriaTerminalScan
{
    public function __construct(private readonly CafeteriaQrScanService $scans) {}

    public function scan(
        string $cardId,
        string $cafeteriaId,
        string $serviceProviderId,
        array $options,
        ?Request $request,
        bool $dryRun,
    ): ?CafeteriaScanOutcome {
        $cafeteria = CafeteriaProvider::whereKey($cafeteriaId)->where('service_provider_id', $serviceProviderId)->where('is_active', true)->first();
        $card = IdCard::find($cardId);
        if ($cafeteria === null || $card === null) {
            return null;
        }

        // Run the exact QR rules; a dry run stops before persistence.
        DB::beginTransaction();
        try {
            $scan = $this->scans->process($card, $cafeteria, now(), null, $options, $request, $dryRun);
            $duplicate = (bool) ($scan['duplicate'] ?? false);
            $allowed = $scan['allowed'] && ! $duplicate;
            if ($dryRun || ! $allowed) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        return new CafeteriaScanOutcome(
            allowed: $allowed,
            duplicate: $duplicate,
            resultCode: $scan['result_code'] ?? null,
            denialReason: $scan['denial_reason'] ?? null,
            transactionNumber: $allowed && ! $dryRun ? $scan['transaction']?->transaction_number : null,
        );
    }
}
