<?php

declare(strict_types=1);

namespace App\Actions\Workflows;

use App\Models\ServiceProvider;
use Illuminate\Support\Facades\DB;

class GenerateSettlementReportAction
{
    public function execute(ServiceProvider $provider, string $period): array
    {
        $amount = (float) DB::table('service_transactions')
            ->where('service_provider_id', $provider->id)
            ->where('status', 'settled')
            ->sum('amount');

        return [
            'provider_id' => $provider->id,
            'period' => $period,
            'settlement_amount' => $amount,
        ];
    }
}
