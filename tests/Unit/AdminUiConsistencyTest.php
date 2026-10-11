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
        'raw dark slate colour' => 185,
        'raw gray border' => 32,
        'raw gray text' => 69,
    ];

    $counts = adminUiCounts();

    foreach ($ceilings as $name => $ceiling) {
        expect($counts[$name])->toBeLessThanOrEqual(
            $ceiling,
            "{$name}: {$counts[$name]} found, ceiling {$ceiling}. Use the shared kit (docs/ui-components.md) instead of new hand-rolled classes.",
        );
    }
});
