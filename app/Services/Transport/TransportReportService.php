<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\Provider;
use App\Models\TransportTransaction;

class TransportReportService
{
    /** @return array<string, mixed> */
    public function transactionSummary(Provider $provider, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = TransportTransaction::query()->where('provider_id', $provider->id);

        if ($dateFrom !== null) {
            $query->whereDate('transaction_date', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('transaction_date', '<=', $dateTo);
        }

        return [
            'total' => (clone $query)->count(),
            'accepted' => (clone $query)->where('status', 'accepted')->count(),
            'rejected' => (clone $query)->where('status', 'rejected')->count(),
        ];
    }
}
