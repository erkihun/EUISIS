<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ServiceTransactions\RecordServiceTransactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ServiceTransactionRequest;
use App\Http\Resources\VerificationResultResource;
use App\Models\Entitlement;
use App\Models\IdCard;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Services\Verification\VerifyCardForServiceAction;
use DomainException;

class ServiceTransactionController extends Controller
{
    public function __invoke(
        ServiceTransactionRequest $request,
        string $serviceType,
        VerifyCardForServiceAction $verifyCardForServiceAction,
        RecordServiceTransactionAction $recordServiceTransactionAction,
    ): VerificationResultResource {
        $serviceTypeModel = ServiceType::query()->where('code', $serviceType)->firstOrFail();
        $provider = ServiceProvider::query()->where('code', $request->string('provider_code'))->firstOrFail();
        $result = $verifyCardForServiceAction->execute($request->string('token')->toString(), $serviceTypeModel, $provider, $request->user(), $request);

        if ($result['allowed'] === true) {
            [$cardId] = explode('|', $request->string('token')->toString());
            $card = IdCard::query()->with('employee')->findOrFail($cardId);
            $entitlement = Entitlement::query()
                ->where('employee_id', $card->employee_id)
                ->where('service_type_id', $serviceTypeModel->id)
                ->first();

            try {
                $recordServiceTransactionAction->execute(
                    $card->employee,
                    $card,
                    $serviceTypeModel,
                    $provider,
                    $entitlement,
                    'authorized',
                    $request->user(),
                    [
                        'reference' => $request->input('reference'),
                        'amount' => $request->input('amount'),
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
