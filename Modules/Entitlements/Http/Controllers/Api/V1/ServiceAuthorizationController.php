<?php

declare(strict_types=1);

namespace Modules\Entitlements\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ServiceAuthorizeRequest;
use App\Http\Resources\VerificationResultResource;
use App\Services\Integration\IntegrationAccessService;
use Modules\Entitlements\Application\Actions\ServiceTransactions\VerifyCardForServiceAction;
use Modules\Entitlements\Domain\Models\ServiceProvider;
use Modules\Entitlements\Domain\Models\ServiceType;

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
