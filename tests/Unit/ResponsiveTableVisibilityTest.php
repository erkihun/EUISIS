<?php

declare(strict_types=1);

/*
 * Lists show a table on wide screens and a card list on phones: the table is
 * `hidden md:table`, the list `md:hidden`. A refactor onto the shared table
 * kit once kept `hidden` and dropped the breakpoint, so the feedback inbox,
 * daily activity and field work lists rendered no rows at all on a desktop.
 * A hidden table or cell must say at which width it comes back.
 */

/** @return list<string> */
function responsiveTableSourceFiles(): array
{
    $files = [];
    $base = (string) realpath(__DIR__.'/../..');

    foreach (['resources/js', 'packages/euisis-ui/src'] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.tsx')) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }

    return $files;
}

/**
 * Every `<table>`, `<th>` and `<td>` whose class names hide it outright,
 * returned as "path:line <tag>" so a failure points at the element.
 *
 * @return list<string>
 */
function permanentlyHiddenTableElements(): array
{
    $offenders = [];

    foreach (responsiveTableSourceFiles() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match_all('/<(table|th|td)\b[^>]*?className=(\{.*?\}|"[^"]*")/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches as $match) {
            $tag = $match[1][0];
            $classes = $match[2][0];

            // Only the string literals carry class names; `hidden && …` is a variable.
            preg_match_all('/\'([^\']*)\'|"([^"]*)"|`([^`]*)`/', $classes, $literals);
            $literalText = implode(' ', array_merge($literals[1], $literals[2], $literals[3]));

            if (preg_match('/(?<![\w:-])hidden(?![\w-])/', $literalText) !== 1) {
                continue;
            }

            $display = $tag === 'table' ? 'table' : 'table-cell';
            if (preg_match('/\b(?:sm|md|lg|xl|2xl):'.preg_quote($display, '/').'(?![\w-])/', $literalText) === 1) {
                continue;
            }

            $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
            $offenders[] = str_replace(str_replace('\\', '/', (string) realpath(__DIR__.'/../..')).'/', '', $path).":{$line} <{$tag}>";
        }
    }

    return $offenders;
}

it('never hides a table, header cell or cell at every screen width', function (): void {
    expect(permanentlyHiddenTableElements())->toBe([]);
});

it('shows the feedback inbox table on wide screens', function (): void {
    $source = (string) file_get_contents(__DIR__.'/../../resources/js/Pages/ServiceFeedback/Index.tsx');

    expect($source)
        ->toContain("cx(tableClassName, 'hidden md:table')")
        ->toContain('md:hidden');
});
