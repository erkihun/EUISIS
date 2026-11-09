<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;

// Shared by the ID card tests. Kept out of any *Test.php file: requiring a
// test file re-registers its tests under the requiring file's beforeEach.

/**
 * Landscape keeps the detailed identity grid. Portrait uses the compact
 * organization, photo, employee name and position arrangement.
 */
function frontCard(): IdCard
{
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-FRONT-1', 'full_name' => 'Front Employee',
        'first_name' => 'Front', 'last_name' => 'Employee', 'status' => EmployeeStatus::Active,
        'gender' => 'male', 'date_of_birth' => '1990-03-15',
        'nationality' => 'Ethiopian', 'phone' => '+251911223344',
        'employment_type' => 'permanent',
    ]);

    $type = OrganizationType::query()->create(['code' => 'FRONT-T', 'name_en' => 'Front type']);
    $organization = Organization::query()->create([
        'code' => 'FRONT-ORG', 'name_en' => 'Front Organization', 'name_am' => 'የፊት ድርጅት',
        'organization_type_id' => $type->id, 'status' => OrganizationStatus::Active,
    ]);
    $position = Position::query()->create([
        'organization_id' => $organization->id, 'title_en' => 'Front Officer', 'title_am' => 'የፊት ሹም',
        'code' => 'FRONT-POS', 'job_position_code' => 'POS-FRONT-9',
    ]);
    $version = HierarchyVersion::query()->create(['version_name' => 'front-test', 'status' => HierarchyVersionStatus::Published]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $organization->id, 'position_id' => $position->id,
        'hierarchy_version_id' => $version->id, 'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(), 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => 'CARD-FRONT-1', 'status' => CardStatus::Active,
        'token_hash' => hash('sha256', 'front-token'), 'issued_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
        'token_version' => 3, 'qr_payload' => 'front-payload', 'is_current' => true,
    ]);
    app(CardQrPayloadService::class)->ensurePublicReference($card);

    return $card->refresh();
}

function renderFrontSvg(IdCard $card, string $orientation = 'landscape'): string
{
    return app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh(), $orientation),
    );
}
