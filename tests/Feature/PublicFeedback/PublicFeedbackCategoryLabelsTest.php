<?php

declare(strict_types=1);

/*
 * The public form asks two different questions and must say so in both
 * languages: the relevant work unit (WHERE the issue belongs) and the issue
 * category (WHAT KIND of issue it is). The labels and helpers live in the
 * front-end dictionaries; this pins the wording so an edit cannot quietly
 * bring back the ambiguous "Category".
 */

function pfclDictionary(string $locale, string $module): array
{
    $source = file_get_contents(base_path("resources/js/i18n/{$locale}/{$module}.ts"));
    preg_match_all("/^\s+([A-Za-z0-9_]+): '((?:[^'\\\\]|\\\\.)*)',$/mu", (string) $source, $matches, PREG_SET_ORDER);

    return array_combine(array_column($matches, 1), array_map(fn (string $value): string => str_replace("\\'", "'", $value), array_column($matches, 2)));
}

it('labels the two questions distinctly in English', function (): void {
    $en = pfclDictionary('en', 'serviceFeedback');

    expect($en['relevantWorkUnit'])->toBe('Relevant Work Unit')
        ->and($en['relevantWorkUnitHint'])->toStartWith('Select the work unit that your feedback, suggestion or complaint is about.')
        ->and($en['issueCategory'])->toBe('Issue Category')
        ->and($en['issueCategoryHint_feedback'])->toBe('Select the type of issue your feedback is about.')
        ->and($en['issueCategoryHint_suggestion'])->toBe('Select the type of issue or improvement your suggestion relates to.')
        ->and($en['issueCategoryHint_complaint'])->toBe('Select the type of issue your complaint relates to.')
        ->and($en['generalNotSure'])->toBe('General / Not sure');
});

it('labels the two questions distinctly in Amharic', function (): void {
    $am = pfclDictionary('am', 'serviceFeedback');

    expect($am['relevantWorkUnit'])->toBe('የሚመለከተው የሥራ ክፍል')
        ->and($am['relevantWorkUnitHint'])->toStartWith('ጥቆማዎ፣ አስተያየትዎ ወይም ቅሬታዎ የሚመለከተውን የሥራ ክፍል ይምረጡ።')
        ->and($am['issueCategory'])->toBe('የጉዳዩ ዓይነት')
        ->and($am['issueCategoryHint_feedback'])->toBe('አስተያየትዎ የሚመለከተውን የጉዳይ ዓይነት ይምረጡ።')
        ->and($am['issueCategoryHint_suggestion'])->toBe('ጥቆማዎ የሚመለከተውን የጉዳይ ወይም የማሻሻያ ዓይነት ይምረጡ።')
        ->and($am['issueCategoryHint_complaint'])->toBe('ቅሬታዎ የሚመለከተውን የጉዳይ ዓይነት ይምረጡ።')
        ->and($am['generalNotSure'])->toBe('አጠቃላይ / አላውቅም')
        // No English fallback hides behind an Amharic key.
        ->and(preg_match('/[A-Za-z]{3,}/', $am['issueCategory'].$am['relevantWorkUnit'].$am['issueCategoryHint_complaint']))->toBe(0);
});

it('calls the column "Issue category" in exports and staff screens, in both languages', function (): void {
    expect(trans('service-feedback.export_columns.category', [], 'en'))->toBe('Issue category')
        ->and(trans('service-feedback.export_columns.category', [], 'am'))->toBe('የጉዳዩ ዓይነት')
        ->and(pfclDictionary('en', 'situationRoom')['categoriesTitle'])->toBe('By issue category')
        ->and(pfclDictionary('am', 'situationRoom')['categoriesTitle'])->toBe('በጉዳዩ ዓይነት');
});
