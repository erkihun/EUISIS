<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VerifyCardApiRequest;
use App\Http\Resources\VerificationResultResource;
use App\Services\Integration\IntegrationAccessService;
use Modules\Entitlements\Contracts\CardServiceAuthorization;
use Modules\Entitlements\Contracts\ReadModels\ServicePointRecord;
use Modules\Entitlements\Contracts\ReadModels\ServiceTypeRecord;

class CardVerificationController extends Controller
{
    public function __invoke(VerifyCardApiRequest $request, CardServiceAuthorization $cards, IntegrationAccessService $access): VerificationResultResource
    {
        $serviceType = ServiceTypeRecord::query()->where('code', $request->string('service_type'))->firstOrFail();
        $provider = $request->filled('provider_code')
            ? ServicePointRecord::query()->where('code', $request->string('provider_code'))->first()
            : null;

        // A bound application acts for its own provider when the request names none.
        $provider ??= $access->boundProvider($request);

        return new VerificationResultResource(
            $cards->verifyToken(
                $request->string('token')->toString(),
                (string) $serviceType->id,
                $provider === null ? null : (string) $provider->id,
                $access->humanActor($request),
                $request,
            )
        );
    }
}
