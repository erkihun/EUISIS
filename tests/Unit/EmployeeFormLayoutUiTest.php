<?php

declare(strict_types=1);

/**
 * Employee Create / Edit form layout.
 *
 * These are source assertions rather than rendered-DOM assertions, matching the
 * convention already used by EmployeeCreatePositionAutofillUiTest — the project
 * has no JS test runner, so the guard against a regression is that the markup
 * contract stays in the file.
 */
function employeeFormSource(string $page): string
{
    return file_get_contents(dirname(__DIR__, 2)."/resources/js/Pages/Employees/{$page}.tsx");
}

function employeeFormLayoutSource(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/employees/EmployeeFormLayout.tsx');
}

// ── 1 & 2. Both pages render the sectioned card layout ────────────────────

test('the create page renders the five required sections as cards', function (): void {
    $source = employeeFormSource('Create');

    expect($source)
        ->toContain('import {')
        ->toContain("from '@/Components/employees/EmployeeFormLayout'")
        ->toContain("t('employees.sectionBasic')")
        ->toContain("t('employees.sectionContact')")
        ->toContain("t('employees.sectionEmployment')")
        ->toContain("t('employees.sectionEmergency')")
        ->toContain("t('employees.sectionSystem')");
});

test('the edit page renders the sectioned card layout', function (): void {
    $source = employeeFormSource('Edit');

    expect($source)
        ->toContain("from '@/Components/employees/EmployeeFormLayout'")
        ->toContain("t('employees.sectionBasic')")
        ->toContain("t('employees.sectionContact')")
        ->toContain("t('employees.sectionEmployment')")
        ->toContain("t('employees.sectionEmergency')");
});

test('every section card carries an icon', function (): void {
    foreach (['Create', 'Edit'] as $page) {
        $source = employeeFormSource($page);

        expect($source)
            ->toContain('icon={<BasicIcon />}')
            ->toContain('icon={<ContactIcon />}')
            ->toContain('icon={<EmploymentIcon />}')
            ->toContain('icon={<EmergencyIcon />}');
    }

    // The System card exists only on Create — Edit has no notes field.
    expect(employeeFormSource('Create'))->toContain('icon={<SystemIcon />}');
});

test('the create page is titled Create Employee and the edit page Update Employee', function (): void {
    expect(employeeFormSource('Create'))->toContain("title={t('employees.createEmployee')}");
    expect(employeeFormSource('Edit'))->toContain("title={t('employees.updateEmployee')}");
});

// ── 3. Responsive: single column on mobile, two on desktop ────────────────

test('section cards stack on mobile and split into two columns on desktop', function (): void {
    $layout = employeeFormLayoutSource();

    // The grid only engages from the md breakpoint, so mobile is single column.
    expect($layout)->toContain('grid gap-x-5 gap-y-4 md:grid-cols-2');
});

test('the layout guards against horizontal overflow on narrow screens', function (): void {
    $layout = employeeFormLayoutSource();

    // min-w-0 on the field and card content lets long values shrink inside the
    // grid track instead of forcing the page wider than the viewport.
    expect($layout)
        ->toContain('min-w-0')
        ->toContain('truncate');

    foreach (['Create', 'Edit'] as $page) {
        $source = employeeFormSource($page);

        // No fixed pixel width or forced min-width would survive a 320px screen.
        expect($source)
            ->not->toContain('w-[')
            ->not->toContain('min-w-[');

        // The action bar reflows to a stacked column on the narrowest screens.
        expect(employeeFormLayoutSource())->toContain('flex-col-reverse gap-2 sm:flex-row');
    }
});

/*
 * Create runs the full width of the content area and takes a third column from
 * `xl` up; Edit is still the capped, centred column. The two pages deliberately
 * differ here, so each is asserted on its own terms.
 */
test('the create form fills the content area and widens its cards', function (): void {
    $source = employeeFormSource('Create');

    expect($source)
        ->not->toContain('mx-auto w-full max-w-5xl')
        ->toContain('<form onSubmit={submit} className="w-full">')
        // Full width without extra columns would leave every control stretched.
        ->toContain('wide');

    expect(employeeFormLayoutSource())->toContain('xl:grid-cols-3');
});

test('the edit form stays width-capped and centred', function (): void {
    expect(employeeFormSource('Edit'))->toContain('mx-auto w-full max-w-5xl');
});

// ── 4. Position context renders read-only ─────────────────────────────────

test('position context renders read-only values with a Position Selected badge', function (): void {
    $source = employeeFormSource('Create');

    expect($source)
        ->toContain('placementContext ? (')
        ->toContain('<ReadOnlyValue')
        // The badge is shown in the placement card header only with context.
        ->toContain('aside={placementContext ? <PositionSelectedBadge /> : undefined}')
        // The editable placement controls are suppressed while a position is locked.
        ->toContain('disabled={organizationLocked}')
        ->toContain('const positionLocked = selectedPositionId !== null');
});

test('position context offers a Change Position escape hatch and no direct edit', function (): void {
    $source = employeeFormSource('Create');

    expect($source)
        ->toContain("t('employees.changePosition')")
        ->toContain("route('positions.index')")
        ->toContain("t('employees.positionContextLocked')");
});

test('the missing-position warning offers a Select Position action', function (): void {
    $source = employeeFormSource('Create');

    expect($source)
        ->toContain("t('employees.selectVacantPositionFirst')")
        ->toContain("t('employees.selectPosition')");
});

test('the edit page does not offer organization, unit or position controls', function (): void {
    $source = employeeFormSource('Edit');

    // Placement moves through the Transfers workflow; the update endpoint
    // accepts none of these fields, so the form must not present them.
    expect($source)
        ->not->toContain('form.data.organization_id')
        ->not->toContain('form.data.organization_unit_id')
        ->not->toContain('form.data.position_id');
});

// ── 6. Validation errors render under their field ─────────────────────────

test('field errors render beneath the control they belong to', function (): void {
    $layout = employeeFormLayoutSource();

    expect($layout)
        ->toContain('{children}')
        ->toContain('role="alert"')
        ->toContain('text-red-600');
});

test('required fields are marked visibly and for screen readers', function (): void {
    $layout = employeeFormLayoutSource();

    expect($layout)
        ->toContain('required && (')
        ->toContain('text-red-500')
        ->toContain('sr-only');

    // The genuinely required backend fields carry the marker.
    $create = employeeFormSource('Create');

    expect($create)
        ->toContain('error={form.errors.first_name} required')
        ->toContain('error={form.errors.last_name} required')
        ->toContain('error={form.errors.status} required')
        ->toContain('error={form.errors.organization_id} required');
});

// ── Action bar ────────────────────────────────────────────────────────────

test('both pages expose Save and Cancel in a sticky bar', function (): void {
    $layout = employeeFormLayoutSource();

    expect($layout)
        ->toContain('sticky bottom-0')
        ->toContain("t('common.cancel')");

    expect(employeeFormSource('Create'))
        ->toContain('<FormActions')
        ->not->toContain('onSaveAndView={saveAndView}');

    expect(employeeFormSource('Edit'))
        ->toContain('<FormActions')
        ->toContain('onSaveAndView={saveAndView}');
});

test('submitting is blocked while a save is in flight', function (): void {
    // The button is disabled by the layout, and the handlers bail out early so
    // a double Enter press cannot fire two requests.
    expect(employeeFormLayoutSource())->toContain('disabled={processing}');

    foreach (['Create', 'Edit'] as $page) {
        expect(employeeFormSource($page))->toContain('if (form.processing) return;');
    }
});

test('dates use the localized picker rather than a raw ISO input', function (): void {
    foreach (['Create', 'Edit'] as $page) {
        $source = employeeFormSource($page);

        expect($source)
            ->toContain('<LocalizedDatePicker')
            ->not->toContain('type="date"');
    }
});

// ── Localization ──────────────────────────────────────────────────────────

test('every new layout string is translated in both locales', function (): void {
    foreach (['en', 'am'] as $locale) {
        $source = file_get_contents(dirname(__DIR__, 2)."/resources/js/i18n/{$locale}/employees.ts");

        expect($source)
            ->toContain('sectionBasic:')
            ->toContain('sectionContact:')
            ->toContain('sectionEmployment:')
            ->toContain('sectionEmergency:')
            ->toContain('sectionSystem:')
            ->toContain('updateEmployee:')
            ->toContain('saveAndView:')
            ->toContain('positionSelected:')
            ->toContain('positionContextLocked:')
            ->toContain('requiredField:');
    }
});

test('the shared undo label is translated in both locales', function (): void {
    foreach (['en', 'am'] as $locale) {
        $source = file_get_contents(dirname(__DIR__, 2)."/resources/js/i18n/{$locale}/common.ts");

        expect($source)->toContain('undo:');
    }
});
