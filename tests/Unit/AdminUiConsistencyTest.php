<?php

declare(strict_types=1);

/*
 * Admin UI consistency guard (docs/ui-components.md). Admin pages use the
 * shared kit — Button / buttonClassName, Input / inputClassName,
 * Select / selectClassName, the table kit and the `--app-*` colour tokens —
 * instead of hand-rolled classes, so one element looks the same on every
 * page and in both themes.
 *
 * Each count below is a ceiling, not a target: it may only go down. When a
 * clean-up lowers a count, lower the number here in the same change.
 */

function adminTsxFiles(): array
{
    $root = dirname(__DIR__, 2).'/resources/js';
    $excluded = '#/(Pages/(Auth|Public|Employee|ProviderPortal|Transport|Discipline|Cafeteria/Portal)/|Pages/(Welcome|Error)\.tsx$|Components/(public|ProviderPortal|transport|discipline|ui)/)#';
    $files = [];

    foreach (['Pages', 'Components', 'Layouts'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, '.tsx') && ! preg_match($excluded, $path)) {
                $files[] = $path;
            }
        }
    }

    return $files;
}

/** @return array<string, int> */
function adminUiCounts(): array
{
    $patterns = [
        // Table cells, tables and table sections take the table kit's classes.
        'static table class' => '/<(table|thead|tbody|th|td)\b[^>]*?\sclassName="/',
        // Primary, destructive and outline buttons take buttonClassName / <Button>.
        'hand-rolled primary button' => '/<button\b[^>]*?className="[^"]*bg-\[color:var\(--color-primary\)\]/',
        'hand-rolled destructive button' => '/<button\b[^>]*?className="[^"]*\bbg-red-(600|700)\b/',
        // Text-like inputs, selects and textareas take inputClassName / selectClassName / textareaClassName.
        'hand-rolled select or textarea' => '/<(select|textarea)\b[^>]*?className="[^"]*\bborder\b/',
        // Neutral colours come from the --app-* tokens, which also cover dark mode.
        'raw dark slate colour' => '/\bdark:(text|bg|border|divide)-slate-\d/',
        'raw gray border' => '/\bborder-gray-(100|200|300)\b/',
        'raw gray text' => '/(?<![:\w-])text-gray-\d00\b/',
        // Lists page through ListPagination / Pagination: one bar, a bounded set of pages.
        'hand-rolled pagination' => '/\b(current_page|currentPage)\s*[-+]\s*1\b|\blinks\.map\(/',
        'one button per page' => '/Array\.from\(\{\s*length:\s*[\w.?]*last_?[pP]age/',
        // Lists filter through FilterPanel / AppFilterBar: labelled fields, Filter then Reset.
        'search form outside the filter panel' => '/<form\b(?:[^>]|=>)*?onSubmit=\{[^}]*router\.get\(/',
        'select that reloads the page' => '/<(select|Select)\b(?:[^>]|=>)*?onChange=\{(?:[^}]|\}\))*?router\.get\(/',
        // The Breeze-era wrappers are gone; forms use the kit directly (docs/admin-ui-consistency.md).
        'legacy form component' => '/<(TextInput|InputLabel|InputError|PrimaryButton|SecondaryButton|DangerButton)\b/',
        // Destructive and workflow actions confirm through useConfirm(); window.confirm stays only for
        // synchronous "leave this page?" guards.
        'native confirm' => '/\b(?:window\.)?confirm\(\s*(?:t\(|[\'"`])/',
        // Surfaces use the radius tokens (rounded-card / rounded-panel / rounded-control).
        'large radius' => '/\brounded-(xl|2xl|3xl)\b/',
        // A record's actions open from one ⋮ menu (AppActionMenu) at the end of its row; a page
        // neither lists them as text links nor builds its own dropdown.
        'row action links outside the menu' => '/<(?:Link|a|button)\b(?:[^>]|=>)*?className=\{actionLinkClassName\(/',
        'hand-rolled dropdown' => '/\bcreatePortal\(/',
        // A row action is the bare verb (Edit, Delete, View, Restore from common.*), never
        // "<verb> <entity>": the row already says what the record is.
        'entity-named row action' => '/label:\s*t\(\'[\w.]+\.(?:edit|delete|archive|restore|view|remove)[A-Z][A-Za-z]*\'\)/',
    ];
    $counts = array_fill_keys(array_keys($patterns), 0);

    foreach (adminTsxFiles() as $file) {
        $source = file_get_contents($file);
        foreach ($patterns as $name => $pattern) {
            $counts[$name] += preg_match_all($pattern, $source);
        }
    }

    return $counts;
}

it('keeps hand-rolled admin UI from growing', function (): void {
    $ceilings = [
        'static table class' => 0,
        'hand-rolled primary button' => 0,
        'hand-rolled destructive button' => 0,
        'hand-rolled select or textarea' => 1,
        // What is left sits almost entirely in files that carry uncommitted work (IdCards/Show,
        // IdCards/Preview, CardPrintExportModal, Grievances/Committees/Show); the rest is listed in
        // docs/admin-ui-consistency.md under "Remaining". Lower these as those files are cleaned.
        'raw dark slate colour' => 102,
        'raw gray border' => 20,
        'raw gray text' => 27,
        'hand-rolled pagination' => 0,
        'legacy form component' => 0,
        // Three "leave this page?" guards (employee create, public site management, system settings).
        'native confirm' => 3,
        // IdCards/Show (uncommitted work).
        'large radius' => 1,
        'one button per page' => 0,
        // The institution picker on an assessment cycle (a form, not a list filter).
        'search form outside the filter panel' => 1,
        // View switchers, not filters: the performance dashboard cycle and the
        // daily-activity report type each change what the whole page shows.
        'select that reloads the page' => 2,
        // Table rows open their actions from AppActionMenu. What is left are text links in cards, detail
        // pages, form repeaters and the organization-design trees (ActivityTree, FunctionTree), plus the
        // PageHeader back link; lower this as those move to buttons or the menu.
        'row action links outside the menu' => 113,
        // The Ethiopian date-picker popover and the two ID-card print modals (uncommitted work) still portal.
        'hand-rolled dropdown' => 3,
        'entity-named row action' => 0,
    ];

    $counts = adminUiCounts();

    foreach ($ceilings as $name => $ceiling) {
        expect($counts[$name])->toBeLessThanOrEqual(
            $ceiling,
            "{$name}: {$counts[$name]} found, ceiling {$ceiling}. Use the shared kit (docs/ui-components.md) instead of new hand-rolled classes.",
        );
    }
});

it('renders every admin page title in the heading band', function (): void {
    // A page passes its title through the layout's `header` prop (normally a <PageHeader>). A bare
    // <AuthenticatedLayout>, or a <PageHeader> rendered inside the body, puts the title below the
    // breadcrumb band and out of line with every other page. Wrappers that render the layout for a
    // module (grievances' Workspace) follow the same rule.
    $offenders = [];
    foreach (adminTsxFiles() as $file) {
        $source = (string) file_get_contents($file);
        if (! str_contains($source, '<AuthenticatedLayout')) {
            continue;
        }
        $bareLayout = preg_match('/<AuthenticatedLayout(?![^>]*\bheader=)[^>]*>/', $source) === 1;
        $bodyHeader = str_contains($source, '<PageHeader') && ! str_contains($source, 'header={');
        if ($bareLayout || $bodyHeader) {
            $offenders[] = substr($file, strpos($file, '/resources/js/') + 14);
        }
    }

    expect($offenders)->toBe([]);
});

it('lets the layout own page width: forms take the form measure, nothing narrows itself', function (): void {
    // docs/admin-ui-consistency.md "Page width": list and detail pages fill the content column;
    // create, edit and shared form pages pass contentWidth="form" and start at the same left edge.
    // A page that centres and narrows its own content (`mx-auto max-w-…xl`) breaks both rules.
    $allowedCentring = [
        'Pages/Assessments/Workspace/Assess.tsx', // the sticky save bar centres its own row inside a fixed strip
    ];
    $selfNarrowing = [];
    $formWithoutMeasure = [];
    $listOrDetailWithFormMeasure = [];

    foreach (adminTsxFiles() as $file) {
        $relative = substr($file, strpos($file, '/resources/js/') + 14);
        if (! str_starts_with($relative, 'Pages/')) {
            continue;
        }
        $source = (string) file_get_contents($file);
        $narrow = '(?:2xl|3xl|4xl|5xl|6xl|7xl)';
        if (! in_array($relative, $allowedCentring, true)
            && preg_match('/className="[^"]*\bmx-auto\b[^"]*\bmax-w-'.$narrow.'\b|className="[^"]*\bmax-w-'.$narrow.'\b[^"]*\bmx-auto\b/', $source)) {
            $selfNarrowing[] = $relative;
        }
        if (! str_contains($source, '<AuthenticatedLayout')) {
            continue;
        }
        $kind = basename($relative, '.tsx');
        $hasFormMeasure = str_contains($source, 'contentWidth="form"');
        if (in_array($kind, ['Create', 'Edit', 'Form'], true) && ! $hasFormMeasure) {
            $formWithoutMeasure[] = $relative;
        }
        if (in_array($kind, ['Index', 'Show'], true) && $hasFormMeasure) {
            $listOrDetailWithFormMeasure[] = $relative;
        }
    }

    expect($selfNarrowing)->toBe([]);
    expect($formWithoutMeasure)->toBe([]);
    expect($listOrDetailWithFormMeasure)->toBe([]);
});

it('creates and edits records on their own pages, never in a dialog on the list', function (): void {
    // A list's "Add" opens Create.tsx and a row's Edit opens Edit.tsx (docs/admin-ui-consistency.md
    // "Create and edit"). Dialogs stay for confirmations, previews and actions such as an import.
    $offenders = [];
    foreach (adminTsxFiles() as $file) {
        $relative = substr($file, strpos($file, '/resources/js/') + 14);
        if (! preg_match('#^Pages/.*/Index\.tsx$#', $relative)) {
            continue;
        }
        $source = (string) file_get_contents($file);
        if (preg_match('/\b(?:post|put)\(route\(\'[a-z.-]+\.(?:store|update)\'/', $source) && preg_match('/<(?:AppDialog|Modal)\b/', $source)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([
        // The cafeteria settings page adds provider users from a settings section, not from a list; documented under "Remaining".
        'Pages/Cafeteria/Settings/Index.tsx',
    ]);
});
