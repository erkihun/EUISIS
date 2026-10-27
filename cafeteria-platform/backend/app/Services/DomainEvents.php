<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoreAuditLog;
use App\Models\OutboxEvent;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Core's integration events, derived from the audited changes so that
 * every master-data change that other systems care about is published from one
 * place. The event row and its pending webhook deliveries are written in the
 * transaction of the change itself (outbox): no event for a change that rolled
 * back, no lost event for one that committed. Delivery happens later
 * (core:deliver-webhooks), so a failing receiver never undoes a Core change.
 *
 * Payloads are minimal: identifiers, codes, statuses and dates; personal
 * details never travel in an event (consumers read what their scopes allow).
 */
final class DomainEvents
{
    public const PAYLOAD_VERSION = 1;

    /** audited action => published event types */
    public const MAP = [
        'organization.created' => ['organization.created'],
        'organization.updated' => ['organization.updated'],
        'organization.deleted' => ['organization.deleted'],
        'hierarchy.publish' => ['hierarchy.published'],
        'hierarchy.snapshotted' => ['hierarchy.published'],
        'unit.created' => ['unit.created'],
        'unit.updated' => ['unit.updated'],
        'position.created' => ['position.created'],
        'position.updated' => ['position.updated'],
        // An employee exists for other systems once approved (ADR 0008): drafts are not published.
        'employee.approved' => ['employee.created', 'assignment.created'],
        'employee.updated' => ['employee.updated'],
        'employee.status_changed' => ['employee.status.changed'],
        'assignment.transferred' => ['assignment.changed'],
        'assignment.ended' => ['assignment.ended'],
        'assignment.resumed' => ['assignment.created'],
        'card_request.approved' => ['card.approved'],
        'card.print' => ['card.printed'],
        'card.issue' => ['card.issued'],
        'card.activate' => ['card.activated'],
        'card.suspend' => ['card.suspended'],
        'card.reinstate' => ['card.reinstated'],
        'card.revoke' => ['card.revoked'],
        'card.report_lost' => ['card.lost'],
        'card.report_damaged' => ['card.damaged'],
        'card.replaced' => ['card.replaced'],
        'card.expired' => ['card.expired'],
    ];

    /** The fields of a change that may travel in an event. */
    private const DATA_FIELDS = ['status', 'code', 'employee_number', 'job_position_code', 'card_number', 'card_id', 'request_id',
        'position_id', 'assignment_id', 'organization_id', 'organization_unit_id', 'parent_organization_id', 'version_id',
        'is_active', 'effective_from', 'effective_to', 'assignment_status', 'request_type'];

    /** @var array<string, list<string>> per-request subtree cache */
    private array $subtrees = [];

    public function __construct(private readonly OrganizationScope $scope) {}

    /** @return list<string> every event type the Core publishes */
    public static function types(): array
    {
        $types = array_merge(...array_values(self::MAP));
        array_push($types, 'organization.archived', 'position.status.changed');

        return array_values(array_unique($types));
    }

    public function publish(CoreAuditLog $log): void
    {
        $types = self::MAP[$log->action] ?? [];
        if ($types === []) {
            return;
        }
        $new = $log->new_values ?? [];
        if ($log->action === 'organization.updated' && in_array($new['status'] ?? null, OrganizationUsage::ENDED, true)) {
            $types[] = 'organization.archived';
        }
        if ($log->action === 'position.updated' && array_key_exists('is_active', $new)) {
            $types[] = 'position.status.changed';
        }

        $data = ['entity_uuid' => $log->subject_id, 'organization_id' => $log->organization_id]
            + array_intersect_key($new, array_flip(self::DATA_FIELDS));
        if ($log->old_values !== null) {
            $data['previous'] = array_intersect_key($log->old_values, array_flip(self::DATA_FIELDS));
        }
        if ($log->action === 'employee.updated') {
            // Which details changed, never their values.
            $data['changed_fields'] = array_keys($new);
        }

        foreach ($types as $type) {
            $event = OutboxEvent::query()->create([
                'id' => (string) Str::uuid7(),
                'event_type' => $type,
                'entity_type' => $log->subject_type,
                'entity_id' => $log->subject_id,
                'organization_id' => $log->organization_id,
                'payload_version' => self::PAYLOAD_VERSION,
                'data' => $data,
                'request_id' => $log->request_id,
                'occurred_at' => $log->created_at,
            ]);
            $this->fanOut($event);
        }
    }

    private function fanOut(OutboxEvent $event): void
    {
        $rows = [];
        foreach (WebhookEndpoint::query()->where('is_active', true)->get(['id', 'event_types', 'scope_organization_id']) as $endpoint) {
            $types = $endpoint->event_types ?? [];
            if (! in_array('*', $types, true) && ! in_array($event->event_type, $types, true)) {
                continue;
            }
            if ($endpoint->scope_organization_id !== null && ($event->organization_id === null
                || ! in_array($event->organization_id, $this->subtrees[$endpoint->scope_organization_id] ??= $this->scope->subtree($endpoint->scope_organization_id), true))) {
                continue;
            }
            $rows[] = [
                'id' => (string) Str::uuid7(), 'webhook_endpoint_id' => $endpoint->id, 'outbox_event_id' => $event->id,
                'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        if ($rows !== []) {
            DB::table('webhook_deliveries')->insert($rows);
        }
    }
}
