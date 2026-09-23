<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The structured request types the single workflow engine supports.
 *
 * One lifecycle serves every type; the type only decides which payload rules
 * apply, which entity the change targets, and which implementing unit key the
 * request is routed to.
 */
enum OrganizationalChangeRequestType: string
{
    // Organization / unit
    case AddOrganizationUnit = 'add_organization_unit';
    case UpdateOrganizationUnit = 'update_organization_unit';
    case MoveOrganizationUnit = 'move_organization_unit';
    case DeactivateOrganizationUnit = 'deactivate_organization_unit';
    case ChangeStructuralParent = 'change_structural_parent';
    case ChangeFunctionalRelationship = 'change_functional_relationship';

    // Position
    case AddPosition = 'add_position';
    case UpdatePosition = 'update_position';
    case MovePosition = 'move_position';
    case IncreasePositions = 'increase_positions';
    case AbolishPosition = 'abolish_position';
    case ChangePositionStatus = 'change_position_status';
    case ChangeGrade = 'change_grade';

    // Other
    case StructuralChange = 'structural_change';
    case OtherStructureRequest = 'other_structure_request';

    public function category(): OrganizationalChangeRequestCategory
    {
        return match ($this) {
            self::AddOrganizationUnit, self::UpdateOrganizationUnit, self::MoveOrganizationUnit,
            self::DeactivateOrganizationUnit, self::ChangeStructuralParent,
            self::ChangeFunctionalRelationship => OrganizationalChangeRequestCategory::OrganizationUnit,

            self::AddPosition, self::UpdatePosition, self::MovePosition, self::IncreasePositions,
            self::AbolishPosition, self::ChangePositionStatus,
            self::ChangeGrade => OrganizationalChangeRequestCategory::Position,

            self::StructuralChange, self::OtherStructureRequest => OrganizationalChangeRequestCategory::Other,
        };
    }

    /** The master-data entity this type acts on, as stored on request items. */
    public function entityType(): OrganizationalChangeEntityType
    {
        return match ($this->category()) {
            OrganizationalChangeRequestCategory::OrganizationUnit => OrganizationalChangeEntityType::OrganizationUnit,
            OrganizationalChangeRequestCategory::Position => OrganizationalChangeEntityType::Position,
            OrganizationalChangeRequestCategory::Other => OrganizationalChangeEntityType::Narrative,
        };
    }

    public function action(): OrganizationalChangeAction
    {
        return match ($this) {
            self::AddOrganizationUnit, self::AddPosition => OrganizationalChangeAction::Create,
            self::UpdateOrganizationUnit, self::UpdatePosition, self::ChangeGrade,
            self::ChangePositionStatus, self::ChangeFunctionalRelationship => OrganizationalChangeAction::Update,
            self::MoveOrganizationUnit, self::MovePosition,
            self::ChangeStructuralParent => OrganizationalChangeAction::Move,
            self::DeactivateOrganizationUnit => OrganizationalChangeAction::Deactivate,
            self::AbolishPosition => OrganizationalChangeAction::Abolish,
            self::IncreasePositions => OrganizationalChangeAction::Increase,
            self::StructuralChange, self::OtherStructureRequest => OrganizationalChangeAction::Narrative,
        };
    }

    /**
     * Whether the type targets an existing master-data record. Create and
     * narrative types do not, so no entity_id is required on the item.
     */
    public function requiresExistingEntity(): bool
    {
        return ! in_array($this->action(), [OrganizationalChangeAction::Create, OrganizationalChangeAction::Narrative], true);
    }

    /**
     * Structural unit changes participate in hierarchy versioning; position
     * changes use the position/establishment history architecture instead.
     */
    public function affectsHierarchyVersion(): bool
    {
        return in_array($this, [
            self::AddOrganizationUnit,
            self::MoveOrganizationUnit,
            self::ChangeStructuralParent,
            self::StructuralChange,
        ], true);
    }

    /**
     * The request permission a user needs to raise this type. Narrative types
     * fall back to the module-level create permission.
     */
    public function requestPermission(): string
    {
        return match ($this) {
            self::AddOrganizationUnit => 'organization-units.request_create',
            self::UpdateOrganizationUnit, self::ChangeFunctionalRelationship => 'organization-units.request_update',
            self::MoveOrganizationUnit, self::ChangeStructuralParent => 'organization-units.request_move',
            self::DeactivateOrganizationUnit => 'organization-units.request_deactivate',
            self::AddPosition => 'positions.request_create',
            self::UpdatePosition, self::ChangeGrade, self::ChangePositionStatus => 'positions.request_update',
            self::MovePosition => 'positions.request_move',
            self::IncreasePositions => 'positions.request_increase',
            self::AbolishPosition => 'positions.request_abolish',
            self::StructuralChange, self::OtherStructureRequest => 'organizational-change-requests.create',
        };
    }

    /**
     * Configurable routing key used by {@see ImplementingUnitResolver} to find
     * the responsible implementing unit. Deliberately generic: no government
     * department name is hard-coded here.
     */
    public function implementingUnitKey(): string
    {
        return match ($this->category()) {
            OrganizationalChangeRequestCategory::OrganizationUnit => 'structure_management',
            OrganizationalChangeRequestCategory::Position => 'establishment_management',
            OrganizationalChangeRequestCategory::Other => 'structure_management',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
