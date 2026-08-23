<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use App\Services\EmployeeServiceEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Integration endpoints for approved external applications.
 *
 * Responses are deliberately minimal: enough to confirm a credential and decide
 * service eligibility, and nothing more. No national id, phone, email, address,
 * salary or document reference is ever returned.
 */
class IdCardVerificationApiController extends Controller
{
    public function __construct(
        private readonly EmployeeServiceEligibilityService $eligibilityService,
        private readonly WriteAuditLogAction $writeAuditLogAction,
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
                'employee.currentAssignment.organization:id,code,name_en,name_am',
                'employee.currentAssignment.position:id,job_position_code,title_en,title_am',
            ])
            ->first();

        // The caller may be an ExternalApplication rather than a User, and the
        // audit action only accepts a User actor. Record who called in the
        // reason instead of passing an incompatible actor.
        $caller = $request->user();
        $callerLabel = $caller instanceof User
            ? 'user'
            : 'external application '.($caller->code ?? 'unknown');

        $this->writeAuditLogAction->execute(
            AuditEventType::VerificationPerformed,
            $caller instanceof User ? $caller : null,
            reason: 'API card verification by '.$callerLabel,
            request: $request,
        );

        if ($card === null) {
            return response()->json([
                'valid' => false,
                'status' => 'not_found',
                'error_code' => 'card_not_found',
            ], 404);
        }

        $isExpired = $card->expires_at !== null && $card->expires_at->isPast();
        $isActive = $card->status === CardStatus::Active && $card->revoked_at === null && ! $isExpired;
        $assignment = $card->employee?->currentAssignment;

        return response()->json([
            'valid' => $isActive,
            'status' => $isExpired && $card->status === CardStatus::Active
                ? CardStatus::Expired->value
                : $card->status->value,
            'card' => [
                'card_number' => $card->card_number,
                'issued_at' => $card->issued_at?->toDateString(),
                'expires_at' => $card->expires_at?->toDateString(),
            ],
            'employee' => $card->employee === null ? null : [
                'employee_number' => $card->employee->employee_number,
                'full_name' => $card->employee->full_name,
                'status' => $card->employee->status->value,
                // The ID photo lets an operator confirm the person presenting
                // the card is its holder — the point of a scan terminal.
                // Absolute, because the caller is a different host; null when
                // the employee has no photo on file.
                'photo_url' => $this->photoUrl($card->employee),
            ],
            'organization' => $assignment?->organization === null ? null : [
                'code' => $assignment->organization->code,
                'name_en' => $assignment->organization->name_en,
                'name_am' => $assignment->organization->name_am,
            ],
            'position' => $assignment?->position === null ? null : [
                'code' => $assignment->position->job_position_code,
                'title_en' => $assignment->position->title_en,
                'title_am' => $assignment->position->title_am,
            ],
        ]);
    }

    /**
     * Absolute URL of the employee's ID photo, or null when none is stored.
     *
     * The model's photo_url accessor returns a root-relative path, which an
     * integration on another host cannot resolve, so it is made absolute here.
     */
    private function photoUrl(Employee $employee): ?string
    {
        $path = $employee->photo_path;

        return blank($path) ? null : url('storage/'.ltrim((string) $path, '/'));
    }

    /**
     * GET /api/v1/employees/{employee}/service-eligibility?service_type=cafeteria
     *
     * Delegates to the shared eligibility service so API callers and internal
     * flows can never disagree about whether a card grants service.
     */
    public function eligibility(Request $request, Employee $employee): JsonResponse
    {
        $serviceType = $request->string('service_type')->toString();

        $card = IdCard::query()
            ->where('employee_id', $employee->getKey())
            ->where('is_current', true)
            ->first();

        $result = $this->eligibilityService->check(
            $employee,
            $card,
            $serviceType,
            $request->user(),
            null,
            $request,
        );

        return response()->json([
            'eligible' => $result['eligible'],
            'reason_code' => $result['reason_code'],
            'message' => $result['message'],
            'card_status' => $result['card_status'],
            'employee' => [
                'employee_number' => $employee->employee_number,
                'status' => $employee->status->value,
            ],
        ], $result['eligible'] ? 200 : 403);
    }
}
