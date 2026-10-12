<?php

/*
 * resources/data/amharic-terminology.json is the canonical Amharic glossary
 * (docs/amharic-terminology-guide.md). The translation audit enforces its
 * deprecated list, so the file must stay well formed and the guide must show
 * every term it holds.
 */
function amharicGlossary(): array
{
    return json_decode((string) file_get_contents(resource_path('data/amharic-terminology.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('gives every glossary term English, Amharic and a known status', function () {
    $glossary = amharicGlossary();
    $statuses = array_keys($glossary['statuses']);

    $problems = [];
    $ids = [];
    foreach ($glossary['terms'] as $term) {
        if (($term['en'] ?? '') === '' || ! preg_match('/\p{Ethiopic}|^[A-Z]{2,}$/u', $term['am'] ?? '') || ! in_array($term['status'] ?? null, $statuses, true)) {
            $problems[] = $term['id'] ?? json_encode($term);
        }
        $ids[] = $term['id'];
    }

    expect($problems)->toBe([])
        ->and(array_unique($ids))->toHaveCount(count($ids));
});

it('compiles every deprecated pattern and names its replacement', function () {
    $problems = [];
    foreach (amharicGlossary()['deprecated'] as $entry) {
        $valid = @preg_match('~'.$entry['pattern'].'~u', '') !== false;
        if (! $valid || ($entry['recommended'] ?? '') === '' || ! is_bool($entry['block'] ?? null)) {
            $problems[] = $entry['current'];
        }
    }

    expect($problems)->toBe([]);
});

it('lists every glossary term in the terminology guide', function () {
    $guide = (string) file_get_contents(base_path('docs/amharic-terminology-guide.md'));
    $cell = fn (string $text) => str_replace('|', '/', $text);

    // Each term has its own table row: "| English | Amharic".
    $missing = array_values(array_filter(
        amharicGlossary()['terms'],
        fn (array $term) => ! str_contains($guide, '| '.$cell($term['en']).' | '.$cell($term['am'])),
    ));

    expect(array_column($missing, 'id'))->toBe([]);
});
