<?php

use Illuminate\Support\Facades\Process;

/*
 * scripts/i18n-audit.mjs is the localization lint for both locales
 * (docs/amharic-language-audit.md). It loads the React translation tree the
 * way useLocale builds it and every PHP language file, then checks that each
 * key exists in English and Amharic, that no English is left in Amharic values
 * and no Amharic in English ones, that placeholders match, that no blocking
 * deprecated term (resources/data/amharic-terminology.json) is used, and that
 * no status code is shown as bare display text.
 */
function translationAuditReport(): array
{
    static $report = null;

    if ($report === null) {
        $out = storage_path('framework/testing/i18n-audit-report-'.getmypid().'.json');
        $result = Process::path(base_path())->timeout(300)->run(['node', 'scripts/i18n-audit.mjs', '--json', $out]);
        $decoded = is_file($out) ? json_decode((string) file_get_contents($out), true) : null;
        @unlink($out);

        if (! is_array($decoded)) {
            throw new RuntimeException('The translation audit did not run: '.$result->errorOutput().$result->output());
        }
        $report = $decoded;
    }

    return $report;
}

function translationAuditFindings(callable $filter): array
{
    $rows = array_filter(translationAuditReport()['findings'], $filter);

    return array_values(array_map(
        fn (array $f) => "{$f['rule']} {$f['source']}:{$f['key']} ".mb_substr(json_encode($f['detail'], JSON_UNESCAPED_UNICODE), 0, 120),
        $rows,
    ));
}

it('finds no blocking issue in the English and Amharic translations', function () {
    expect(translationAuditFindings(fn (array $f) => $f['severity'] === 'block'))->toBe([]);
});

it('keeps every translation key in both languages', function () {
    expect(translationAuditFindings(fn (array $f) => in_array($f['rule'], ['missing_en', 'missing_am', 'empty'], true)))->toBe([]);
});

it('shows a status code only as a bracketed reference after its Amharic meaning', function () {
    // A code such as NEEDS_DECISION may follow the Amharic wording in brackets, so
    // stewards can search for it; format tokens like {SEQUENCE} are syntax users type.
    expect(translationAuditFindings(fn (array $f) => $f['rule'] === 'raw_enum_in_text'))->toBe([]);
});

it('exits non-zero in strict mode only when a blocking finding exists', function () {
    $result = Process::path(base_path())->timeout(300)->run(['node', 'scripts/i18n-audit.mjs', '--strict']);
    $blocking = translationAuditFindings(fn (array $f) => $f['severity'] === 'block');

    expect($result->exitCode())->toBe($blocking === [] ? 0 : 1);
});
