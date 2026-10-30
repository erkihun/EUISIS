<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ServiceTransactions\VerifyCardForServiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OfflineSyncTransactionRequest;
use App\Models\ServiceType;
use App\Services\Integration\IntegrationAccessService;
use Modules\Providers\Domain\Models\ServiceProvider;

class OfflineSyncController extends Controller
{
    public function __invoke(OfflineSyncTransactionRequest $request, VerifyCardForServiceAction $verifyCardForServiceAction, IntegrationAccessService $access): array
    {
        $results = [];
        $actor = $access->humanActor($request);

        foreach ($request->input('transactions', []) as $index => $transaction) {
            $serviceType = ServiceType::query()->where('code', $transaction['service_type'])->first();
            $provider = ServiceProvider::query()->where('code', $transaction['provider_code'])->first();

            // One bad item never fails the whole batch with a 404: it is reported in place.
            if ($serviceType === null || $provider === null) {
                $results[] = [
                    'index' => $index,
                    'allowed' => false,
                    'result_code' => $serviceType === null ? 'unknown_service_type' : 'unknown_provider',
                    'denial_reason' => $serviceType === null ? 'unknown service type' : 'unknown provider',
                ];

                continue;
            }

            $results[] = ['index' => $index, ...$verifyCardForServiceAction->execute($transaction['token'], $serviceType, $provider, $actor, $request)];
        }

        return ['results' => $results];
    }
}
