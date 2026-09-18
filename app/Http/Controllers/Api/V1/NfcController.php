<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ServiceTransactions\RecordServiceTransactionAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\NfcVerificationResource;
use App\Models\CafeteriaProvider;
use App\Models\Entitlement;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\CredentialVerificationService;
use App\Services\Nfc\NfcAudit;
use App\Services\Nfc\NfcPayloadParser;
use App\Services\Nfc\NfcVerificationService;
use App\Services\Verification\VerifyCardForServiceAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NfcController extends Controller
{
    public function challenge(Request $request, NfcPayloadParser $parser, NfcVerificationService $service)
    {
        $result = $service->challenge($parser->parse($request), $request);

        return response()->json($result, isset($result['challenge']) ? 200 : 403);
    }

    public function verify(Request $request)
    {
        return $this->run($request, 'verify');
    }

    public function eligibility(Request $request)
    {
        return $this->run($request, 'eligibility');
    }

    public function record(Request $request)
    {
        return $this->run($request, 'record');
    }

    private function run(Request $request, string $purpose)
    {
        $payload = app(NfcPayloadParser::class)->parse($request);
        if ($purpose !== 'verify') {
            $request->validate(['service_type' => 'required|string|max:64']);
        }
        if ($purpose === 'record') {
            $request->validate(['reference' => 'required|uuid']);
        }
        $result = DB::transaction(function () use ($request, $payload, $purpose) {
            $result = app(CredentialVerificationService::class)->verify('nfc', $payload, $request, $purpose);
            if (! $result['valid'] || $purpose === 'verify') {
                return $result;
            }
            $terminal = $result['terminal'];
            $card = $result['card'];
            $serviceType = ServiceType::where('code', $payload['service_type'])->first();
            $provider = ServiceProvider::whereKey($terminal->provider_id)->lockForUpdate()->first();
            $allowed = false;
            $reason = 'SERVICE_NOT_ALLOWED';
            $transactionReference = null;
            if ($serviceType && $provider && $provider->status === 'active' && $provider->service_type_id === $serviceType->id) {
                if ($serviceType->code === 'cafeteria') {
                    $cafeteria = CafeteriaProvider::whereKey($terminal->cafeteria_provider_id)->where('service_provider_id', $provider->id)->where('is_active', true)->first();
                    if ($cafeteria) {
                        // Run the exact QR rules; dry-run stops before persistence.
                        DB::beginTransaction();
                        try {
                            $scan = app(CafeteriaQrScanService::class)->process($card, $cafeteria, now(), null, [
                                'usage_mode' => $payload['usage_mode'] ?? 'single_day',
                                'meal_amount' => $payload['meal_amount'] ?? null,
                                // Bound by secure challenge; namespace prevents cross-terminal collisions.
                                'scan_nonce' => hash('sha256', $terminal->id.($payload['reference'] ?? bin2hex(random_bytes(16)))),
                            ], $request, $purpose === 'eligibility');
                            $allowed = $scan['allowed'] && ! ($scan['duplicate'] ?? false);
                            // Denials carry result_code 'rejected'; the specific
                            // cause lives in denial_reason.
                            $reason = ($scan['duplicate'] ?? false) ? 'ALREADY_SERVED' : $this->reason($scan['denial_reason'] ?? $scan['result_code']);
                            $transactionReference = $allowed && $purpose === 'record' ? $scan['transaction']?->transaction_number : null;
                            if ($purpose === 'eligibility' || ! $allowed) {
                                DB::rollBack();
                            } else {
                                DB::commit();
                            }
                        } catch (\Throwable $exception) {
                            DB::rollBack();
                            throw $exception;
                        }
                    }
                } else {
                    $entitlement = Entitlement::where('employee_id', $card->employee_id)->where('service_type_id', $serviceType->id)
                        ->where(fn ($q) => $q->whereNull('service_provider_id')->orWhere('service_provider_id', $provider->id))
                        ->where('status', 'active')
                        ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
                        ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
                        ->lockForUpdate()->first();
                    $check = app(VerifyCardForServiceAction::class)->verifyResolvedCard($card, $serviceType, $provider, null, $request);
                    $allowed = $check['allowed'];
                    $reason = $this->reason($check['result_code']);
                    if ($allowed && $purpose === 'record') {
                        try {
                            $transaction = app(RecordServiceTransactionAction::class)->execute($card->employee, $card, $serviceType, $provider, $entitlement, 'authorized', null,
                                ['reference' => $payload['reference'], 'source' => 'nfc']);
                            $transactionReference = $transaction->reference;
                        } catch (\DomainException) {
                            $allowed = false;
                            $reason = 'ALREADY_SERVED';
                        }
                    }
                }
            }
            $result['eligible'] = $allowed;
            $result['reason_code'] = $allowed ? null : $reason;
            $result['transaction_reference'] = $transactionReference;
            app(NfcAudit::class)->record($purpose === 'record' ? 'service_transaction' : 'service_eligibility', $allowed, $result['reason_code'], $result['credential_model'], $terminal, $request);

            return $result;
        });

        return response()->json((new NfcVerificationResource($result))->resolve(), $result['valid'] && $result['eligible'] !== false ? 200 : 403);
    }

    private function reason(string $reason): string
    {
        return match ($reason) {
            'already_scanned_today', 'no_available_subsidy', 'duplicate_transaction' => 'ALREADY_SERVED',
            'employee_inactive' => 'EMPLOYEE_INACTIVE',
            'id_card_expired' => 'CARD_EXPIRED', 'id_card_lost' => 'CARD_LOST',
            'id_card_replaced' => 'CARD_REPLACED', 'id_card_revoked' => 'CARD_REVOKED',
            'no_active_id_card', 'id_card_not_active', 'id_card_suspended', 'id_card_pending' => 'CARD_INACTIVE',
            default => 'SERVICE_NOT_ALLOWED',
        };
    }
}
