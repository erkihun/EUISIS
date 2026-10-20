<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ServiceAuthorizeRequest;
use App\Http\Resources\VerificationResultResource;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Services\Integration\IntegrationAccessService;
use App\Services\Verification\VerifyCardForServiceAction;

class ServiceAuthorizationController extends Controller
{
    public function __invoke(ServiceAuthorizeRequest $request, string $serviceType, VerifyCardForServiceAction $verifyCardForServiceAction, IntegrationAccessService $access): VerificationResultResource
    {
        $serviceTypeModel = ServiceType::query()->where('code', $serviceType)->firstOrFail();
        $provider = $request->filled('provider_code')
            ? ServiceProvider::query()->where('code', $request->string('provider_code'))->first()
            : null;

        $provider ??= $access->boundProvider($request);

        return new VerificationResultResource(
            $verifyCardForServiceAction->execute(
                $request->string('token')->toString(),
                $serviceTypeModel,
                $provider,
                $access->humanActor($request),
                $request,
            )
        );
    }
}
