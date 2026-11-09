<?php

declare(strict_types=1);

/*
| PHP prints anything before its open tag. A UTF-8 byte-order mark at the top of
| an Amharic lang file put EF BB BF in front of every page that loaded it, which
| breaks JSON and Inertia responses (#3, b91193b). Every PHP file must start with
| the open tag itself, and no template may start with a byte-order mark.
*/

/** @return list<string> */
function strayOutputFiles(string $pattern, bool $mustOpenWithTag): array
{
    $offenders = [];
    foreach (['app', 'bootstrap', 'config', 'database', 'lang', 'routes', 'resources/views'] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = (string) $file;
            if (! preg_match($pattern, $path) || str_contains($path, DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $head = (string) file_get_contents($path, false, null, 0, 8);
            if (str_starts_with($head, "\xEF\xBB\xBF") || ($mustOpenWithTag && ! str_starts_with($head, '<?php'))) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }
    }

    return $offenders;
}

test('every PHP class, config, lang and migration file starts with the open tag', function (): void {
    expect(strayOutputFiles('/(?<!\.blade)\.php$/', true))->toBe([]);
});

test('no Blade template starts with a byte-order mark', function (): void {
    expect(strayOutputFiles('/\.blade\.php$/', false))->toBe([]);
});

test('an Amharic page that loads the letters and inspection text carries no byte-order mark', function (): void {
    app()->setLocale('am');
    __('court::letters.title');
    __('court::case_inspections.title');

    $this->withSession(['app_locale' => 'am'])->get(route('court.applicant.login'))->assertOk()
        ->assertDontSee("\xEF\xBB\xBF", false);
});
