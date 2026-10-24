<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ServiceTransactions\VerifyCardForServiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VerifyCardApiRequest;
use App\Http\Resources\VerificationResultResource;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Services\Integration\IntegrationAccessService;

class CardVerificationController extends Controller
{
    public function __invoke(VerifyCardApiRequest $request, VerifyCardForServiceAction $verifyCardForServiceAction, IntegrationAccessService $access): VerificationResultResource
    {
        $serviceType = ServiceType::query()->where('code', $request->string('service_type'))->firstOrFail();
        $provider = $request->filled('provider_code')
            ? ServiceProvider::query()->where('code', $request->string('provider_code'))->first()
            : null;

        // A bound application acts for its own provider when the request names none.
        $provider ??= $access->boundProvider($request);

        return new VerificationResultResource(
            $verifyCardForServiceAction->execute(
                $request->string('token')->toString(),
                $serviceType,
                $provider,
                $access->humanActor($request),
                $request,
            )
        );
    }
}
