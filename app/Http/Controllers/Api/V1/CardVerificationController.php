<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VerifyCardApiRequest;
use App\Http\Resources\VerificationResultResource;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Services\Verification\VerifyCardForServiceAction;

class CardVerificationController extends Controller
{
    public function __invoke(VerifyCardApiRequest $request, VerifyCardForServiceAction $verifyCardForServiceAction): VerificationResultResource
    {
        $serviceType = ServiceType::query()->where('code', $request->string('service_type'))->firstOrFail();
        $provider = $request->filled('provider_code')
            ? ServiceProvider::query()->where('code', $request->string('provider_code'))->first()
            : null;

        return new VerificationResultResource(
            $verifyCardForServiceAction->execute(
                $request->string('token')->toString(),
                $serviceType,
                $provider,
                $request->user(),
                $request,
            )
        );
    }
}
