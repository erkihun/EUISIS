<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['organizations.view', 'organizations.viewAny'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('OG Viewer', 'web')->givePermissionTo(['organizations.view', 'organizations.viewAny']);
    Role::findOrCreate('OG Outsider', 'web');

    $type = OrganizationType::query()->create(['code' => 'OG-TYPE', 'name_en' => 'Organogram Type']);

    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'OG-A',
        'name_en' => 'Organogram Organization',
        'status' => 'active',
    ]);

    $this->otherOrg = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'OG-B',
        'name_en' => 'Other Organogram Organization',
        'status' => 'active',
    ]);

    $this->parentUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'OG-A-U1',
        'name_en' => 'Parent Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->childUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'parent_unit_id' => $this->parentUnit->id,
        'code' => 'OG-A-U2',
        'name_en' => 'Child Unit',
        'unit_type' => 'team',
        'status' => 'active',
    ]);

    $this->occupiedPosition = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->parentUnit->id,
        'job_position_code' => 'OG-A-P1',
        'old_code' => 'OLD-P1',
        'bpr_name' => 'BPR Position One',
        'title_en' => 'Occupied Position',
        'is_active' => true,
    ]);

    $this->vacantPosition = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->childUnit->id,
        'job_position_code' => 'OG-A-P2',
        'title_en' => 'Vacant Position',
        'is_active' => true,
    ]);

    $this->employee = Employee::query()->create([
        'employee_number' => 'OG-EMP-1',
        'first_name' => 'Abebe',
        'last_name' => 'Bekele',
        'full_name' => 'Abebe Bekele',
        'national_id' => '1234567890123456',
        'phone' => '0911000000',
        'status' => EmployeeStatus::Active->value,
    ]);

    EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id,
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->parentUnit->id,
        'position_id' => $this->occupiedPosition->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);
});

function ogUser(string $role = 'OG Viewer', ?Organization $scopeTo = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    if ($scopeTo !== null) {
        $user->organizationScopes()->create([
            'organization_id' => $scopeTo->id,
            'scope_type' => 'self',
            'is_active' => true,
        ]);
    }

    return $user->fresh();
}

it('shows the generate organogram link on the organization detail page', function (): void {
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/js/Pages/Organizations/Show.tsx');

    expect($source)
        ->toContain("route('organizations.organogram', organization.id)")
        ->toContain("t('organizations.generateOrganogram')");
});

it('renders the organogram for an authorized user', function (): void {
    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Organogram')
            ->where('tree.organization.code', 'OG-A')
        );
});

it('forbids a user without view permission', function (): void {
    $this->actingAs(ogUser('OG Outsider'))
        ->get(route('organizations.organogram', $this->org))
        ->assertForbidden();
});

it('forbids a scoped admin from generating an out-of-scope organogram', function (): void {
    $this->actingAs(ogUser('OG Viewer', $this->org))
        ->get(route('organizations.organogram', $this->otherOrg))
        ->assertForbidden();
});

it('includes organization units nested by parent', function (): void {
    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertInertia(function (Assert $page): void {
            $units = $page->toArray()['props']['tree']['units'];

            expect($units)->toHaveCount(1)
                ->and($units[0]['code'])->toBe('OG-A-U1')
                ->and($units[0]['children'])->toHaveCount(1)
                ->and($units[0]['children'][0]['code'])->toBe('OG-A-U2');
        });
});

it('includes positions with code, old code and bpr name', function (): void {
    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertInertia(function (Assert $page): void {
            $position = $page->toArray()['props']['tree']['units'][0]['positions'][0];

            expect($position['code'])->toBe('OG-A-P1')
                ->and($position['old_code'])->toBe('OLD-P1')
                ->and($position['bpr_name'])->toBe('BPR Position One');
        });
});

it('shows the assigned employee summary on an occupied position', function (): void {
    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertInertia(function (Assert $page): void {
            $position = $page->toArray()['props']['tree']['units'][0]['positions'][0];

            expect($position['occupancy'])->toBe('occupied')
                ->and($position['assignment']['employee']['employee_number'])->toBe('OG-EMP-1')
                ->and($position['assignment']['employee']['full_name'])->toBe('Abebe Bekele')
                ->and($position['assignment']['employee']['status'])->toBe('active');
        });
});

it('marks an unassigned position as vacant', function (): void {
    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertInertia(function (Assert $page): void {
            $position = $page->toArray()['props']['tree']['units'][0]['children'][0]['positions'][0];

            expect($position['code'])->toBe('OG-A-P2')
                ->and($position['occupancy'])->toBe('vacant')
                ->and($position['assignment'])->toBeNull();
        });
});

it('never exposes sensitive employee fields', function (): void {
    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertInertia(function (Assert $page): void {
            $json = json_encode($page->toArray()['props']['tree']);

            // National id and phone must never reach the organogram payload.
            expect($json)->not->toContain('1234567890123456')
                ->and($json)->not->toContain('0911000000')
                ->and($json)->not->toContain('national_id')
                ->and($json)->not->toContain('phone');
        });
});

it('never includes another organization structure', function (): void {
    OrganizationUnit::query()->create([
        'organization_id' => $this->otherOrg->id,
        'code' => 'OG-B-U1',
        'name_en' => 'Foreign Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertInertia(function (Assert $page): void {
            expect(json_encode($page->toArray()['props']['tree']))->not->toContain('OG-B-U1');
        });
});

it('downloads a pdf when the pdf format is requested', function (): void {
    $response = $this->actingAs(ogUser())
        ->get(route('organizations.organogram', ['organization' => $this->org, 'format' => 'pdf']));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('organogram-OG-A.pdf');
});

it('forbids a pdf export outside the actor scope', function (): void {
    $this->actingAs(ogUser('OG Viewer', $this->org))
        ->get(route('organizations.organogram', ['organization' => $this->otherOrg, 'format' => 'pdf']))
        ->assertForbidden();
});

it('renders a box-and-line chart component rather than the indented list', function (): void {
    $page = file_get_contents(dirname(__DIR__, 3).'/resources/js/Pages/Organizations/Organogram.tsx');

    expect($page)
        ->toContain('<OrganogramChart')
        ->not->toContain('<OrganizationStructureTree');
});

it('builds every organogram box from payload data with no sample labels', function (): void {
    $base = dirname(__DIR__, 3).'/resources/js/Components/organizations';
    $chart = file_get_contents($base.'/OrganogramChart.tsx');
    // Node content lives in the shared primitives every layout imports.
    $shared = file_get_contents($base.'/organogram/shared.tsx');

    expect($shared)
        ->toContain('organization.code')
        ->toContain('employee.employee_number')
        ->toContain('employee.full_name')
        ->toContain('position.bpr_name');

    expect($chart)
        ->toContain("t('organizations.zoomIn')")
        ->toContain("t('organizations.zoomOut')")
        ->toContain("t('organizations.expandAll')")
        ->toContain("t('organizations.collapseAll')");

    // No hard-coded org-chart sample text in the host or any layout.
    $sources = [$chart, $shared];
    foreach ([
        'VerticalOrganogram', 'HorizontalOrganogram', 'CompactOrganogram',
        'PositionFocusedOrganogram', 'UnitFocusedOrganogram',
    ] as $component) {
        $sources[] = file_get_contents($base."/organogram/{$component}.tsx");
    }

    foreach ($sources as $source) {
        foreach (['CEO', 'Manager', 'Workers', 'Lorem', 'John Doe'] as $sample) {
            expect($source)->not->toContain($sample);
        }
    }
});

it('does not render employee fields beyond number, name and status in the chart', function (): void {
    $chart = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/organogram/shared.tsx');

    expect($chart)
        ->not->toContain('national_id')
        ->not->toContain('employee.phone')
        ->not->toContain('date_of_birth');
});

it('offers png and pdf export controls on the organogram page', function (): void {
    $page = file_get_contents(dirname(__DIR__, 3).'/resources/js/Pages/Organizations/Organogram.tsx');

    expect($page)
        ->toContain("t('organizations.exportPng')")
        ->toContain("t('organizations.exportPdf')")
        ->toContain("t('organizations.printOrganogram')")
        // Loading + failure feedback.
        ->toContain("t('organizations.exporting')")
        ->toContain("t('organizations.exportFailed')")
        // Buttons disabled while a capture is running.
        ->toContain('disabled={exporting !== null}');
});

it('captures the chart with the shared html-to-image settings', function (): void {
    $hook = file_get_contents(dirname(__DIR__, 3).'/resources/js/hooks/useOrganogramExport.ts');

    expect($hook)
        ->toContain("import { toPng } from 'html-to-image'")
        // High-DPI raster; skipFonts avoids the cross-origin SecurityError.
        ->toContain('pixelRatio: 2')
        ->toContain('skipFonts: true')
        // Full structure, not just the visible viewport.
        ->toContain('node.scrollWidth')
        ->toContain('node.scrollHeight')
        // Filename carries organization code and date.
        ->toContain('buildFileName');
});

it('exports use only the already-authorized on-screen payload', function (): void {
    $hook = file_get_contents(dirname(__DIR__, 3).'/resources/js/hooks/useOrganogramExport.ts');

    // The hook rasterises a DOM node; it must never fetch its own data.
    expect($hook)
        ->not->toContain('axios')
        ->not->toContain('fetch(')
        ->not->toContain('route(');
});

it('offers all five organogram layout types in the selector', function (): void {
    $shared = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/organogram/shared.tsx');
    $selector = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/organogram/OrganogramTypeSelector.tsx');

    foreach (['vertical', 'horizontal', 'compact', 'position-focused', 'unit-focused'] as $layout) {
        expect($shared)->toContain("'{$layout}'");
    }

    foreach ([
        'layoutVertical', 'layoutHorizontal', 'layoutCompact',
        'layoutPositionFocused', 'layoutUnitFocused',
    ] as $key) {
        expect($shared)->toContain("organizations.{$key}");
    }

    expect($selector)->toContain("t('organizations.organogramType')")
        ->toContain('ORGANOGRAM_LAYOUTS.map');
});

it('renders each layout through its own component from the same payload', function (): void {
    $chart = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/OrganogramChart.tsx');

    expect($chart)
        ->toContain('<VerticalOrganogram')
        ->toContain('<HorizontalOrganogram')
        ->toContain('<CompactOrganogram')
        ->toContain('<PositionFocusedOrganogram')
        ->toContain('<UnitFocusedOrganogram')
        // Every layout is handed the same `tree` prop — no per-layout fetch.
        ->not->toContain('axios')
        ->not->toContain('fetch(');
});

it('keeps the selected layout inside the export capture target', function (): void {
    $chart = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/OrganogramChart.tsx');

    // renderLayout() must sit inside the node carrying captureRef, so PNG/PDF
    // and print always capture the layout currently on screen.
    $captureBlock = substr($chart, strpos($chart, 'ref={captureRef}'));

    expect($captureBlock)->toContain('{renderLayout()}')
        ->and($chart)->toContain('data-organogram-layout={layout}');
});

it('shares node content across layouts instead of duplicating it', function (): void {
    $shared = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/organogram/shared.tsx');

    // Boxes are defined once; employee fields stay limited to the allowed three.
    expect($shared)
        ->toContain('export function OrganizationBox')
        ->toContain('export function UnitBox')
        ->toContain('export function PositionBox')
        ->toContain('export function EmployeeBox')
        ->toContain('employee.employee_number')
        ->toContain('employee.full_name')
        ->not->toContain('national_id')
        ->not->toContain('date_of_birth');

    foreach ([
        'VerticalOrganogram', 'HorizontalOrganogram', 'CompactOrganogram',
        'PositionFocusedOrganogram', 'UnitFocusedOrganogram',
    ] as $component) {
        $source = file_get_contents(dirname(__DIR__, 3)."/resources/js/Components/organizations/organogram/{$component}.tsx");
        expect($source)->toContain("from './shared'");
    }
});

it('offers all three display depth options', function (): void {
    $base = dirname(__DIR__, 3).'/resources/js/Components/organizations/organogram';
    $shared = file_get_contents($base.'/shared.tsx');
    $selector = file_get_contents($base.'/OrganogramDepthSelector.tsx');

    foreach (['organization_unit', 'position', 'employee'] as $depth) {
        expect($shared)->toContain("'{$depth}'");
    }

    foreach (['depthUntilUnit', 'depthUntilPosition', 'depthUntilEmployee'] as $key) {
        expect($shared)->toContain("organizations.{$key}");
    }

    expect($selector)->toContain("t('organizations.displayDepth')")
        ->toContain('ORGANOGRAM_DEPTHS.map');
});

it('prunes the tree by depth before any layout renders it', function (): void {
    $shared = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/organogram/shared.tsx');

    expect($shared)
        ->toContain('export function applyDepth')
        // organization_unit drops positions entirely.
        ->toContain("depth === 'organization_unit'")
        // position keeps the box but strips the employee assignment.
        ->toContain('assignment: null');
});

it('feeds the depth-pruned tree to every layout and the export target', function (): void {
    $chart = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/OrganogramChart.tsx');

    expect($chart)->toContain('applyDepth(tree, depth)');

    // No layout may receive the unpruned tree.
    expect(substr_count($chart, 'tree={visibleTree}'))->toBe(5)
        ->and($chart)->not->toContain('tree={tree}');

    // Depth persists in the URL alongside layout.
    expect($chart)->toContain("url.searchParams.set('depth', depth)")
        ->toContain('data-organogram-depth={depth}');

    // renderLayout() sits inside captureRef, so PNG/PDF/print follow depth.
    $captureBlock = substr($chart, strpos($chart, 'ref={captureRef}'));
    expect($captureBlock)->toContain('{renderLayout()}');
});

it('keeps depth selection independent of the layout selector', function (): void {
    $chart = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/OrganogramChart.tsx');

    // Both selectors are rendered, and depth is applied regardless of layout.
    expect($chart)
        ->toContain('<OrganogramTypeSelector')
        ->toContain('<OrganogramDepthSelector')
        ->toContain('useMemo(() => applyDepth(tree, depth)');
});

it('includes a photo url on the assigned employee summary', function (): void {
    $this->employee->update(['photo_path' => 'employees/og-emp-1.jpg']);

    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertInertia(function (Assert $page): void {
            $employee = $page->toArray()['props']['tree']['units'][0]['positions'][0]['assignment']['employee'];

            expect($employee['photo_url'])->toContain('employees/og-emp-1.jpg')
                // The raw storage path is never exposed.
                ->and($employee)->not->toHaveKey('photo_path');
        });
});

it('returns a null photo url when the employee has no photo', function (): void {
    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertInertia(function (Assert $page): void {
            $employee = $page->toArray()['props']['tree']['units'][0]['positions'][0]['assignment']['employee'];

            expect($employee['photo_url'])->toBeNull();
        });
});

it('keeps the employee payload limited to the four allowed fields plus id', function (): void {
    $this->employee->update(['photo_path' => 'employees/og-emp-1.jpg']);

    $this->actingAs(ogUser())
        ->get(route('organizations.organogram', $this->org))
        ->assertInertia(function (Assert $page): void {
            $employee = $page->toArray()['props']['tree']['units'][0]['positions'][0]['assignment']['employee'];

            foreach (['national_id', 'phone', 'email', 'date_of_birth', 'photo_path'] as $field) {
                expect($employee)->not->toHaveKey($field);
            }

            expect($employee)->toHaveKeys(['employee_number', 'full_name', 'status', 'photo_url']);
        });
});

it('renders the photo only inside the employee node, which depth gates', function (): void {
    $shared = file_get_contents(dirname(__DIR__, 3).'/resources/js/Components/organizations/organogram/shared.tsx');

    expect($shared)
        ->toContain('export function EmployeePhoto')
        // Placeholder when there is no photo.
        ->toContain('initialsOf(employee.full_name)')
        ->toContain("t('organizations.noPhoto')")
        // crossOrigin keeps the canvas exportable for PNG/PDF.
        ->toContain('crossOrigin="anonymous"');

    // EmployeePhoto is only reachable through the employee node, and
    // applyDepth nulls the assignment below depth = employee.
    expect($shared)->toContain('const employee = position.assignment?.employee')
        ->toContain('assignment: null');
});

it('waits for photos to load before rasterising an export', function (): void {
    $hook = file_get_contents(dirname(__DIR__, 3).'/resources/js/hooks/useOrganogramExport.ts');

    // waitForCardAssets resolves once every <img> in the node has decoded.
    expect($hook)->toContain('await waitForCardAssets(node)');
});
