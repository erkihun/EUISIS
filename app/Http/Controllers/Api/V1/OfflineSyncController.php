<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OfflineSyncTransactionRequest;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Services\Verification\VerifyCardForServiceAction;

class OfflineSyncController extends Controller
{
    public function __invoke(OfflineSyncTransactionRequest $request, VerifyCardForServiceAction $verifyCardForServiceAction): array
    {
        $results = [];

        foreach ($request->input('transactions', []) as $transaction) {
            $serviceType = ServiceType::query()->where('code', $transaction['service_type'])->firstOrFail();
            $provider = ServiceProvider::query()->where('code', $transaction['provider_code'])->firstOrFail();

            $results[] = $verifyCardForServiceAction->execute($transaction['token'], $serviceType, $provider, $request->user(), $request);
        }

        return ['results' => $results];
    }
}
