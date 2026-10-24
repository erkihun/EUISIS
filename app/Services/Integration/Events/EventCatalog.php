<?php

declare(strict_types=1);

namespace App\Services\Integration\Events;

/**
 * The external integration event contract, version 1
 * (docs/integration/event-catalog.md).
 *
 * Convention: `event_type` is a dotted name without a version
 * (`employee.updated`) and `event_version` an integer. A breaking change to a
 * type's `data` adds a new version; additive optional fields do not.
 *
 * Each type names its aggregate, the partner scope that may see it, and the
 * exact `data` keys it may carry. Anything else is refused at publish time, so
 * a full model can never leak into an event.
 */
final class EventCatalog
{
    public const VERSION = 1;

    /** Changed-field categories an employee.updated event may name. */
    public const EMPLOYEE_PUBLIC_FIELDS = [
        'employee_number' => 'employee_number',
        'first_name' => 'name', 'middle_name' => 'name', 'last_name' => 'name', 'full_name' => 'name', 'name_en' => 'name',
        'gender' => 'gender',
        'employment_type' => 'employment_type',
        'photo_path' => 'photo',
    ];

    private const ORG = ['organization_uuid', 'code', 'name_en', 'name_am', 'status'];

    private const UNIT = ['unit_uuid', 'organization_uuid', 'parent_unit_uuid', 'code', 'name_en', 'name_am', 'status'];

    private const POSITION = ['position_uuid', 'organization_uuid', 'unit_uuid', 'code', 'title_en', 'title_am', 'is_active'];

    /** Occupancy of a position: never names the employee (positions.read holders may not see employees). */
    private const OCCUPANCY = ['position_uuid', 'organization_uuid', 'occupancy_uuid', 'effective_date', 'state'];

    private const CARD_STATE = ['card_uuid', 'employee_uuid', 'status', 'previous_status', 'is_current', 'state'];

    private const ASSIGNMENT = ['assignment_uuid', 'employee_uuid', 'organization_uuid', 'unit_uuid', 'position_uuid', 'assignment_status', 'effective_from', 'effective_to', 'is_current'];

    /**
     * @var array<string, array{aggregate: string, scope: string, data: list<string>, tombstone: bool|null}>
     *                                                                                                       tombstone: true = always, false = never, null = decided by the state carried (status_changed)
     */
    private const TYPES = [
        'organization.created' => ['aggregate' => 'organization', 'scope' => 'organizations.read', 'data' => self::ORG, 'tombstone' => false],
        'organization.updated' => ['aggregate' => 'organization', 'scope' => 'organizations.read', 'data' => [...self::ORG, 'changed_fields'], 'tombstone' => false],
        'organization.status_changed' => ['aggregate' => 'organization', 'scope' => 'organizations.read', 'data' => ['organization_uuid', 'status', 'previous_status', 'state'], 'tombstone' => null],

        'organization_unit.created' => ['aggregate' => 'organization_unit', 'scope' => 'organization_units.read', 'data' => self::UNIT, 'tombstone' => false],
        'organization_unit.updated' => ['aggregate' => 'organization_unit', 'scope' => 'organization_units.read', 'data' => [...self::UNIT, 'changed_fields'], 'tombstone' => false],
        'organization_unit.moved' => ['aggregate' => 'organization_unit', 'scope' => 'organization_units.read', 'data' => ['unit_uuid', 'organization_uuid', 'parent_unit_uuid', 'previous_parent_unit_uuid'], 'tombstone' => false],
        'organization_unit.status_changed' => ['aggregate' => 'organization_unit', 'scope' => 'organization_units.read', 'data' => ['unit_uuid', 'organization_uuid', 'status', 'previous_status', 'state'], 'tombstone' => null],

        'position.created' => ['aggregate' => 'position', 'scope' => 'positions.read', 'data' => self::POSITION, 'tombstone' => false],
        'position.updated' => ['aggregate' => 'position', 'scope' => 'positions.read', 'data' => [...self::POSITION, 'changed_fields'], 'tombstone' => false],
        'position.status_changed' => ['aggregate' => 'position', 'scope' => 'positions.read', 'data' => ['position_uuid', 'organization_uuid', 'is_active', 'state'], 'tombstone' => null],
        'position.archived' => ['aggregate' => 'position', 'scope' => 'positions.read', 'data' => ['position_uuid', 'organization_uuid', 'state', 'reason_category'], 'tombstone' => true],
        // Stage 6: explicit occupancy events (a seat of the position taken or released).
        'position.occupied' => ['aggregate' => 'position', 'scope' => 'positions.read', 'data' => self::OCCUPANCY, 'tombstone' => false],
        'position.vacated' => ['aggregate' => 'position', 'scope' => 'positions.read', 'data' => self::OCCUPANCY, 'tombstone' => false],

        'employee.created' => ['aggregate' => 'employee', 'scope' => 'employees.basic_read', 'data' => ['employee_uuid', 'employee_number', 'status'], 'tombstone' => false],
        'employee.updated' => ['aggregate' => 'employee', 'scope' => 'employees.basic_read', 'data' => ['employee_uuid', 'employee_number', 'status', 'changed_fields'], 'tombstone' => false],
        'employee.status_changed' => ['aggregate' => 'employee', 'scope' => 'employees.basic_read', 'data' => ['employee_uuid', 'employee_number', 'status', 'previous_status', 'state'], 'tombstone' => null],

        'assignment.created' => ['aggregate' => 'assignment', 'scope' => 'employee_assignments.read', 'data' => self::ASSIGNMENT, 'tombstone' => false],
        'assignment.updated' => ['aggregate' => 'assignment', 'scope' => 'employee_assignments.read', 'data' => [...self::ASSIGNMENT, 'changed_fields'], 'tombstone' => false],
        'assignment.closed' => ['aggregate' => 'assignment', 'scope' => 'employee_assignments.read', 'data' => ['assignment_uuid', 'employee_uuid', 'organization_uuid', 'assignment_status', 'effective_to', 'state'], 'tombstone' => true],
        'employee.assignment_changed' => ['aggregate' => 'employee_placement', 'scope' => 'employee_assignments.read', 'data' => ['employee_uuid', 'assignment_uuid', 'previous_assignment_uuid', 'organization_uuid', 'unit_uuid', 'position_uuid'], 'tombstone' => false],
        'employee.transferred' => ['aggregate' => 'employee_placement', 'scope' => 'employee_assignments.read', 'data' => [
            'employee_uuid', 'transfer_uuid', 'transfer_kind', 'effective_date',
            'previous_assignment_uuid', 'previous_organization_uuid', 'previous_unit_uuid', 'previous_position_uuid',
            'assignment_uuid', 'organization_uuid', 'unit_uuid', 'position_uuid', 'visibility',
        ], 'tombstone' => false],

        'id_card.issued' => ['aggregate' => 'id_card', 'scope' => 'id_cards.verify', 'data' => ['card_uuid', 'employee_uuid', 'status', 'is_current', 'issued_at', 'expires_at'], 'tombstone' => false],
        'id_card.reprinted' => ['aggregate' => 'id_card', 'scope' => 'id_cards.verify', 'data' => ['card_uuid', 'employee_uuid', 'status', 'is_current', 'printed_at'], 'tombstone' => false],
        'id_card.revoked' => ['aggregate' => 'id_card', 'scope' => 'id_cards.verify', 'data' => ['card_uuid', 'employee_uuid', 'status', 'is_current', 'revoked_at', 'state'], 'tombstone' => true],
        'id_card.status_changed' => ['aggregate' => 'id_card', 'scope' => 'id_cards.verify', 'data' => self::CARD_STATE, 'tombstone' => null],
        // Stage 6: explicit card-state events, recorded beside id_card.status_changed (which stays).
        'id_card.activated' => ['aggregate' => 'id_card', 'scope' => 'id_cards.verify', 'data' => [...self::CARD_STATE, 'expires_at'], 'tombstone' => false],
        'id_card.suspended' => ['aggregate' => 'id_card', 'scope' => 'id_cards.verify', 'data' => self::CARD_STATE, 'tombstone' => true],
        'id_card.expired' => ['aggregate' => 'id_card', 'scope' => 'id_cards.verify', 'data' => self::CARD_STATE, 'tombstone' => true],

        'hierarchy_version.published' => ['aggregate' => 'hierarchy_version', 'scope' => 'organization_structure.read', 'data' => ['hierarchy_version_uuid', 'version_name', 'effective_from', 'published_at'], 'tombstone' => false],
    ];

    /** States a consumer should treat as "remove from current / stop serving". */
    public const INACTIVE_STATES = ['inactive', 'archived', 'merged', 'dissolved', 'superseded', 'revoked', 'closed', 'suspended', 'retired', 'terminated', 'deceased', 'transferred', 'lost', 'damaged', 'expired', 'replaced'];

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function aggregate(string $type): string
    {
        return self::TYPES[$type]['aggregate'];
    }

    public static function scope(string $type): string
    {
        return self::TYPES[$type]['scope'];
    }

    /** @return list<string> */
    public static function dataKeys(string $type): array
    {
        return self::TYPES[$type]['data'];
    }

    /** Event types a holder of these scopes may read or subscribe to. @param list<string> $scopes @return list<string> */
    public static function typesForScopes(array $scopes): array
    {
        return array_values(array_filter(self::types(), fn (string $type): bool => in_array(self::scope($type), $scopes, true)));
    }

    /** @param array<string, mixed> $data */
    public static function isTombstone(string $type, array $data): bool
    {
        $rule = self::TYPES[$type]['tombstone'];

        if ($rule !== null) {
            return $rule;
        }

        $state = $data['state'] ?? null;

        return is_string($state) && in_array($state, self::INACTIVE_STATES, true);
    }

    /**
     * Problems with a payload; empty when valid. Unknown keys, a missing
     * aggregate identity, or nested objects (a serialised model) are refused.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function validate(string $type, array $data): array
    {
        if (! self::exists($type)) {
            return ['unknown_event_type'];
        }

        $problems = [];
        $allowed = self::dataKeys($type);

        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $problems[] = 'unexpected_field:'.$key;
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value) && $key !== 'changed_fields') {
                $problems[] = 'nested_value:'.$key;
            }
            if ($key === 'changed_fields' && (! is_array($value) || ! array_is_list($value))) {
                $problems[] = 'invalid_changed_fields';
            }
        }

        $identity = $allowed[0];
        if (! isset($data[$identity]) || ! is_string($data[$identity]) || $data[$identity] === '') {
            $problems[] = 'missing_identity:'.$identity;
        }

        return $problems;
    }
}
