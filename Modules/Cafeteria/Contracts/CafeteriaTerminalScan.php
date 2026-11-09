<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Contracts;

use Illuminate\Http\Request;
use Modules\Cafeteria\Contracts\Data\CafeteriaScanOutcome;

/**
 * A card presented at a registered cafeteria terminal (NFC). Runs the same
 * rules as a QR scan at that cafeteria. The cafeteria comes from the
 * terminal, never from the request; the employee organization's policy
 * prices the meal.
 */
interface CafeteriaTerminalScan
{
    /**
     * Null when the terminal's cafeteria is not an active location of that
     * service provider. A dry run (eligibility check) and a refused scan
     * leave no record; an accepted scan is committed.
     *
     * @param  array{usage_mode?: string, scan_nonce: string, service_terminal_id: string}  $options
     */
    public function scan(
        string $cardId,
        string $cafeteriaId,
        string $serviceProviderId,
        array $options,
        ?Request $request,
        bool $dryRun,
    ): ?CafeteriaScanOutcome;
}
