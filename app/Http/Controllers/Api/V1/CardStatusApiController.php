<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\WriteAuditLogAction;
use App\Backbone\Contracts\CardCredentialStatus;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\CardVerification;
use App\Models\ExternalApplication;
use App\Models\IdCard;
use App\Services\Integration\IntegrationAccessService;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Card credential status and explicit verification records (master
 * backbone, Stage 4). The answer is the backbone's single credential rule
 * (CardCredentialStatus); nothing about the holder beyond the employee UUID
 * is returned. Outside the caller's organization bound a card is reported
 * exactly like an unknown one.
 */
class CardStatusApiController extends Controller
{
    public function __construct(
        private readonly IntegrationAccessService $access,
        private readonly CardCredentialStatus $credentials,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** GET /api/v1/cards/{cardUuid} */
    public function show(Request $request, string $cardUuid): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'id_cards.verify');
        $card = $this->findInBounds($request, $cardUuid, 'API card status read');

        return $card === null
            ? ApiError::response($request, 404, 'card_not_found', 'Card not found.')
            : response()->json(['data' => $this->present($card) + ['checked_at' => now()->toIso8601String()]]);
    }

    /**
     * POST /api/v1/verifications { card_uuid }
     *
     * Verifies the card and keeps a verification record the caller can read
     * back (GET /api/v1/verifications/{id}). Retries with the same
     * Idempotency-Key replay the first answer.
     */
    public function store(Request $request): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'id_cards.verify');
        $data = $request->validate(['card_uuid' => ['required', 'uuid']]);
        $card = $this->findInBounds($request, $data['card_uuid'], 'API verification record');
        if ($card === null) {
            return ApiError::response($request, 404, 'card_not_found', 'Card not found.');
        }

        $result = $this->present($card);
        $principal = $this->access->principal($request);
        $verification = CardVerification::query()->create([
            'id_card_id' => $card->getKey(),
            'api_client_id' => $principal instanceof ExternalApplication ? $principal->getKey() : null,
            'result_code' => $result['status'],
            'allowed' => $result['valid'],
            'request_ip' => $request->ip(),
            'request_user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'response_payload' => ['principal' => $this->principalKey($request), 'channel' => 'api.v1.verifications'],
        ]);

        return response()->json(['data' => $this->presentVerification($verification, $card)], 201);
    }

    /** GET /api/v1/verifications/{verification}: only the principal that made it can read it. */
    public function showVerification(Request $request, string $verification): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'id_cards.verify');
        $record = Str::isUuid($verification) ? CardVerification::query()->find($verification) : null;
        $card = $record?->id_card_id === null ? null : IdCard::query()->find($record->id_card_id);

        if ($record === null || $card === null || ($record->response_payload['principal'] ?? null) !== $this->principalKey($request)) {
            return ApiError::response($request, 404, 'verification_not_found', 'Not Found.');
        }

        return response()->json(['data' => $this->presentVerification($record, $card)]);
    }

    private function findInBounds(Request $request, string $cardUuid, string $what): ?IdCard
    {
        $card = ! Str::isUuid($cardUuid) ? null : IdCard::query()
            ->with('employee.currentAssignment:id,employee_id,organization_id')
            ->where('public_card_uuid', $cardUuid)
            ->first();
        $inBounds = $card !== null && $this->access->canAccessOrganization($request, $card->employee?->currentAssignment?->organization_id);

        $this->audit->execute(
            AuditEventType::VerificationPerformed,
            $this->access->humanActor($request),
            reason: $what.' by '.$this->access->callerLabel($request).($card === null ? ' (card not found)' : ($inBounds ? '' : ' (outside organization bound)')),
            request: $request,
        );

        return $inBounds ? $card : null;
    }

    /** @return array<string, mixed> */
    private function present(IdCard $card): array
    {
        $credential = $this->credentials->evaluate($card);

        return [
            'card_uuid' => $credential->cardUuid,
            'card_number' => $credential->cardNumber,
            'status' => $credential->status,
            'valid' => $credential->valid,
            'is_current' => $credential->isCurrent,
            'expires_at' => $credential->expiresAt,
            'employee_uuid' => $credential->employeeUuid,
            // Lineage: a replacement card is a new credential; both directions are given.
            'replaces_card_uuid' => $credential->previousCardRecordUuid === null ? null : IdCard::query()->whereKey($credential->previousCardRecordUuid)->value('public_card_uuid'),
            'replaced_by_card_uuid' => IdCard::query()->where('previous_card_id', $card->getKey())->value('public_card_uuid'),
        ];
    }

    /** @return array<string, mixed> */
    private function presentVerification(CardVerification $verification, IdCard $card): array
    {
        return [
            'verification_id' => $verification->getKey(),
            'card_uuid' => $card->public_card_uuid,
            'employee_uuid' => (string) $card->employee_id,
            'status' => $verification->result_code,
            'valid' => (bool) $verification->allowed,
            'verified_at' => $verification->created_at?->toIso8601String(),
        ];
    }

    private function principalKey(Request $request): string
    {
        $principal = $this->access->principal($request);

        return $principal === null ? 'anonymous' : class_basename($principal).':'.$principal->getKey();
    }
}
