<?php

declare(strict_types=1);

namespace App\Actions\OrganizationalChange;

use App\Enums\AuditEventType;
use App\Enums\OrganizationalChangeRequestStatus;
use App\Enums\OrganizationalChangeRequestType;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationalChangeRequestItem;
use App\Models\User;
use App\Services\OrganizationalChange\ChangeRequestHistoryRecorder;
use App\Services\OrganizationalChange\ChangeRequestImpactAnalyzer;
use App\Services\OrganizationalChange\ChangeRequestNumberGenerator;
use App\Services\OrganizationalChange\ChangeRequestPayloadValidator;
use App\Services\OrganizationalChange\ChangeRequestScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft request or saves an edit to one.
 *
 * Nothing here touches master data. The proposal is stored as a request item
 * holding a snapshot of the current state and the proposed state, so the
 * reviewer can see a real before/after without anything having changed yet.
 */
final readonly class SaveChangeRequestAction
{
    public function __construct(
        private ChangeRequestScopeService $scope,
        private ChangeRequestPayloadValidator $validator,
        private ChangeRequestNumberGenerator $numbers,
        private ChangeRequestHistoryRecorder $history,
        private ChangeRequestImpactAnalyzer $impact,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(User $actor, array $data, ?Request $httpRequest = null): OrganizationalChangeRequest
    {
        $type = OrganizationalChangeRequestType::from((string) $data['request_type']);

        // Server-side scope: the organization id from the browser is re-checked,
        // never trusted.
        $organizationId = $this->scope->assertCanRequestForOrganization($actor, $data['organization_id'] ?? null);

        if (! $this->scope->canRequestType($actor, $type, $organizationId)) {
            abort(403, __('organizational-change-requests.errors.type_not_permitted'));
        }

        $validated = $this->validator->validate($actor, $type, $organizationId, $data['payload'] ?? []);

        return DB::transaction(function () use ($actor, $data, $type, $organizationId, $validated, $httpRequest): OrganizationalChangeRequest {
            $request = OrganizationalChangeRequest::query()->create([
                'request_no' => $this->numbers->generate(),
                'organization_id' => $organizationId,
                'request_type' => $type->value,
                'category' => $type->category()->value,
                'status' => OrganizationalChangeRequestStatus::Draft->value,
                'priority' => $this->priority($data),
                'requested_by' => $actor->getKey(),
                'reason' => (string) $data['reason'],
                'requested_effective_date' => $data['requested_effective_date'] ?? null,
                'revision' => 1,
            ]);

            $this->writeItem($request, $type, $validated);

            $this->history->record(
                request: $request,
                actor: $actor,
                action: 'created',
                from: null,
                to: OrganizationalChangeRequestStatus::Draft,
                auditEvent: AuditEventType::OrganizationalChangeRequestCreated,
                httpRequest: $httpRequest,
            );

            return $request->fresh(['items']);
        });
    }

    /**
     * Save an edit. Permitted only while the requester still owns the payload:
     * a draft, or a request handed back for correction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(
        OrganizationalChangeRequest $request,
        User $actor,
        array $data,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        if (! $request->status->isRequesterEditable()) {
            throw ValidationException::withMessages([
                'status' => __('organizational-change-requests.errors.not_editable'),
            ]);
        }

        $type = $request->request_type;
        $organizationId = (string) $request->organization_id;

        // The organization and the type are fixed once the request exists;
        // changing either would make the review history meaningless.
        $validated = $this->validator->validate($actor, $type, $organizationId, $data['payload'] ?? []);

        return DB::transaction(function () use ($request, $actor, $data, $type, $validated, $httpRequest): OrganizationalChangeRequest {
            $before = [
                'reason' => $request->reason,
                'requested_effective_date' => $request->requested_effective_date?->toDateString(),
                'proposed_data' => $request->primaryItem()?->proposed_data,
            ];

            $request->forceFill([
                'reason' => (string) ($data['reason'] ?? $request->reason),
                'requested_effective_date' => $data['requested_effective_date'] ?? $request->requested_effective_date,
                'priority' => $this->priority($data, $request->priority),
            ])->save();

            $request->items()->delete();
            $this->writeItem($request, $type, $validated);

            $request->load('items');

            $this->history->record(
                request: $request,
                actor: $actor,
                action: 'updated',
                from: $request->status,
                to: $request->status,
                changedFields: [
                    'before' => $before,
                    'after' => [
                        'reason' => $request->reason,
                        'requested_effective_date' => $request->requested_effective_date?->toDateString(),
                        'proposed_data' => $request->primaryItem()?->proposed_data,
                    ],
                ],
                auditEvent: AuditEventType::OrganizationalChangeRequestUpdated,
                httpRequest: $httpRequest,
            );

            return $request->fresh(['items']);
        });
    }

    /**
     * @param  array{proposed: array<string, mixed>, before: array<string, mixed>|null, entity_id: string|null}  $validated
     */
    private function writeItem(
        OrganizationalChangeRequest $request,
        OrganizationalChangeRequestType $type,
        array $validated,
    ): OrganizationalChangeRequestItem {
        $item = OrganizationalChangeRequestItem::query()->create([
            'request_id' => $request->getKey(),
            'entity_type' => $type->entityType()->value,
            'entity_id' => $validated['entity_id'],
            'action' => $type->action()->value,
            'before_data' => $validated['before'],
            'proposed_data' => $validated['proposed'],
            'sort_order' => 0,
        ]);

        // Impact is computed against live data and stored with the item so the
        // reviewer sees what the request meant when it was written. It is
        // recomputed before implementation.
        $request->setRelation('items', collect([$item]));

        $item->forceFill(['validation_snapshot' => [
            'impact' => $this->impact->analyze($request),
            'captured_at' => now()->toDateTimeString(),
        ]])->save();

        return $item;
    }

    /** @param array<string, mixed> $data */
    private function priority(array $data, string $fallback = 'normal'): string
    {
        $priority = (string) ($data['priority'] ?? $fallback);

        return in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : 'normal';
    }
}
