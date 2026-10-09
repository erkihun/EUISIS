<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workflows\GenerateSettlementReportAction;
use App\Http\Controllers\Controller;
use App\Models\ServiceProvider;

class ProviderSettlementController extends Controller
{
    public function __invoke(ServiceProvider $provider, string $period, GenerateSettlementReportAction $generateSettlementReportAction): array
    {
        return $generateSettlementReportAction->execute($provider, $period);
    }
}
