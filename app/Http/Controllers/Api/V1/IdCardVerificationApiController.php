<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\IdCard;
use App\Services\EmployeeServiceEligibilityService;
use App\Services\Integration\EmployeePhotoLinkService;
use App\Services\Integration\IntegrationAccessService;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Integration endpoints for approved external applications and for people
 * calling with their own permissions.
 *
 * Responses are deliberately minimal: enough to confirm a credential and decide
 * service eligibility, and nothing more. No national id, phone, email, address,
 * salary or document reference is ever returned.
 *
 * Master backbone remediation:
 *   F5  a card is valid only when ALL of: status active, not revoked, not
 *       expired, QR still active, and it is the employee's current card;
 *   F3  stable UUIDs (employee_uuid, card_uuid, organization_uuid) so a
 *       partner never has to invent an identifier for the next call;
 *   F17 the photo is a short-lived signed link, not a public storage URL;
 *   F4  eligibility never passes a machine principal where a person is
 *       expected, and answers with a structured decision;
 *   F2  both endpoints honour the caller's organization bound (404 outside it).
 */
class IdCardVerificationApiController extends Controller
{
    public function __construct(
        private readonly EmployeeServiceEligibilityService $eligibilityService,
        private readonly WriteAuditLogAction $writeAuditLogAction,
        private readonly IntegrationAccessService $access,
        private readonly EmployeePhotoLinkService $photoLinks,
    ) {}

    /**
     * GET /api/v1/id-cards/verify/{token}
     *
     * `token` is the stable public card UUID carried by the printed QR code.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $card = IdCard::query()
            ->where('public_card_uuid', $token)
            ->with([
                // current_assignment_id is the foreign key currentAssignment()
                // belongs to. Omitting it from the select silently yields a
                // null relation, so organization and position came back null
                // for employees that do have an assignment.
                'employee:id,employee_number,full_name,name_en,status,current_assignment_id,photo_path',
                'employee.currentAssignment:id,employee_id,organization_id,organization_unit_id,position_id,assignment_status,is_current',
                'employee.currentAssignment.organization:id,code,name_en,name_am',
                'employee.currentAssignment.position:id,job_position_code,title_en,title_am',
            ])
            ->first();

        $principal = $this->access->principal($request);
        $assignment = $card?->employee?->currentAssignment;
        $inBounds = $card !== null && $this->access->canAccessOrganization($request, $assignment?->organization_id === null ? null : (string) $assignment->organization_id);

        $this->writeAuditLogAction->execute(
            AuditEventType::VerificationPerformed,
            $this->access->humanActor($request),
            reason: 'API card verification by '.$this->access->callerLabel($request).($card === null ? ' (card not found)' : ($inBounds ? '' : ' (outside organization bound)')),
            request: $request,
        );

        // Outside the caller's bound a card is reported exactly like an unknown one.
        if ($card === null || ! $inBounds) {
            return ApiError::response($request, 404, 'card_not_found', 'Card not found.', ['valid' => false, 'status' => 'not_found']);
        }

        $isExpired = $card->expires_at !== null && $card->expires_at->isPast();
        $qrRevoked = $card->qr_status !== null && $card->qr_status !== 'active';
        $isValid = $card->status === CardStatus::Active
            && $card->revoked_at === null
            && ! $isExpired
            && ! $qrRevoked
            && (bool) $card->is_current;

        $status = match (true) {
            $card->status !== CardStatus::Active => $card->status->value,
            $card->revoked_at !== null || $qrRevoked => CardStatus::Revoked->value,
            $isExpired => CardStatus::Expired->value,
            ! $card->is_current => CardStatus::Replaced->value,
            default => CardStatus::Active->value,
        };

        $employee = $card->employee;

        return response()->json([
            'valid' => $isValid,
            'status' => $status,
            'card_uuid' => $card->public_card_uuid,
            'employee_uuid' => $employee?->getKey(),
            'organization_uuid' => $assignment?->organization_id,
            'assignment_status' => $assignment?->assignment_status?->value,
            'verified_at' => now()->toIso8601String(),
            'card' => [
                'card_uuid' => $card->public_card_uuid,
                'card_number' => $card->card_number,
                'issued_at' => $card->issued_at?->toDateString(),
                'expires_at' => $card->expires_at?->toDateString(),
                'is_current' => (bool) $card->is_current,
            ],
            'employee' => $employee === null ? null : [
                'employee_uuid' => $employee->getKey(),
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'status' => $employee->status->value,
                // The ID photo lets an operator confirm the person presenting
                // the card is its holder. A signed link that expires in
                // minutes and is re-authorised when fetched; null when the
                // employee has no photo or the caller may not read photos.
                'photo_url' => $principal !== null && $this->access->mayReadPhotos($request)
                    ? $this->photoLinks->forPrincipal($employee, $principal)
                    : null,
                'photo_expires_in' => EmployeePhotoLinkService::TTL_MINUTES * 60,
            ],
            'organization' => $assignment?->organization === null ? null : [
                'organization_uuid' => $assignment->organization->getKey(),
                'code' => $assignment->organization->code,
                'name_en' => $assignment->organization->name_en,
                'name_am' => $assignment->organization->name_am,
            ],
            'position' => $assignment?->position === null ? null : [
                'code' => $assignment->position->job_position_code,
                'title_en' => $assignment->position->title_en,
                'title_am' => $assignment->position->title_am,
            ],
            'request_id' => ApiError::requestId($request),
        ]);
    }

    /**
     * GET /api/v1/employees/{employee}/service-eligibility?service_type=cafeteria
     *
     * Delegates to the shared eligibility service so API callers and internal
     * flows can never disagree about whether a card grants service.
     */
    public function eligibility(Request $request, Employee $employee): JsonResponse
    {
        $this->access->assertEmployee($request, $employee);

        $validated = $request->validate(['service_type' => ['required', 'string', 'max:64']]);
        $serviceType = $validated['service_type'];

        $card = IdCard::query()
            ->where('employee_id', $employee->getKey())
            ->where('is_current', true)
            ->first();

        $result = $this->eligibilityService->check(
            $employee,
            $card,
            $serviceType,
            $this->access->humanActor($request),
            null,
            $request,
            attemptedBy: $this->access->callerLabel($request),
        );

        $organizationId = $this->access->employeeOrganizationId($employee);

        return response()->json([
            'eligible' => $result['eligible'],
            'reason_code' => $result['reason_code'],
            'message' => $result['message'],
            'card_status' => $result['card_status'],
            'service' => ['service_type' => $serviceType],
            'effective_context' => [
                'employee_uuid' => $employee->getKey(),
                'employee_number' => $employee->employee_number,
                'employee_status' => $employee->status->value,
                'card_uuid' => $card?->public_card_uuid,
                'card_status' => $result['card_status'],
                'organization_uuid' => $organizationId,
            ],
            'employee' => [
                'employee_uuid' => $employee->getKey(),
                'employee_number' => $employee->employee_number,
                'status' => $employee->status->value,
            ],
            'checked_at' => now()->toIso8601String(),
            'request_id' => ApiError::requestId($request),
        ], $result['eligible'] ? 200 : 403);
    }
}
