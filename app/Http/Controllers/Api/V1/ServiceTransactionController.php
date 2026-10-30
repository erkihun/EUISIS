<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ServiceTransactions\RecordServiceTransactionAction;
use App\Actions\ServiceTransactions\VerifyCardForServiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ServiceTransactionRequest;
use App\Http\Resources\VerificationResultResource;
use App\Models\Entitlement;
use App\Models\IdCard;
use App\Models\ServiceType;
use App\Services\Integration\IntegrationAccessService;
use DomainException;
use App\Models\ServiceProvider;

class ServiceTransactionController extends Controller
{
    public function __invoke(
        ServiceTransactionRequest $request,
        string $serviceType,
        VerifyCardForServiceAction $verifyCardForServiceAction,
        RecordServiceTransactionAction $recordServiceTransactionAction,
        IntegrationAccessService $access,
    ): VerificationResultResource {
        $serviceTypeModel = ServiceType::query()->where('code', $serviceType)->firstOrFail();
        $provider = ServiceProvider::query()->where('code', $request->string('provider_code'))->firstOrFail();
        $actor = $access->humanActor($request);
        $result = $verifyCardForServiceAction->execute($request->string('token')->toString(), $serviceTypeModel, $provider, $actor, $request);

        if ($result['allowed'] === true) {
            [$cardId] = explode('|', $request->string('token')->toString());
            $card = IdCard::query()->with('employee')->findOrFail($cardId);

            // F19: the entitlement in force today for this service and provider.
            $entitlement = Entitlement::inForceFor((string) $card->employee_id, (string) $serviceTypeModel->id, (string) $provider->getKey());

            try {
                $recordServiceTransactionAction->execute(
                    $card->employee,
                    $card,
                    $serviceTypeModel,
                    $provider,
                    $entitlement,
                    'authorized',
                    $actor,
                    [
                        'reference' => $request->input('reference'),
                        'amount' => $request->input('amount'),
                        'recorded_by' => $access->callerLabel($request),
                    ],
                );
            } catch (DomainException $exception) {
                $reasonCode = $exception->getMessage() === 'Duplicate provider transaction reference.'
                    ? 'duplicate_transaction'
                    : $exception->getMessage();
                $result = [
                    'allowed' => false,
                    'result_code' => $reasonCode,
                    'denial_reason' => $reasonCode === 'duplicate_transaction'
                        ? 'duplicate transaction'
                        : __("service-eligibility.reasons.{$reasonCode}"),
                    'card_status' => $card->status->value,
                    'employee_status' => $card->employee->status->value,
                    'service_type' => $serviceTypeModel->code,
                ];
            }
        }

        return new VerificationResultResource($result);
    }
}
