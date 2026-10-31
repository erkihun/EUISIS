/**
 * Text the React performance-evaluation pages need that no lang/{en,am}/performance.php
 * key provides (the Blade pages hard-coded it in English, or had no visible label).
 * To be merged into court/i18n/{en,am}.ts under `court.performancePages`; until then
 * court/i18n/usePerformanceText.ts reads it directly as the fallback.
 */
const performancePages = {
    en: {
        // The Add Category slug field's example.
        slug_placeholder: 'e.g. efficiency',
        // The score slider's spoken value.
        score_value: ':score out of 10',
    },
    am: {
        slug_placeholder: 'ለምሳሌ efficiency',
        score_value: 'ከ10 :score',
    },
} as const;

export type PerformancePagesTextKey = keyof typeof performancePages.en;

export default performancePages as { en: Record<PerformancePagesTextKey, string>; am: Record<PerformancePagesTextKey, string> };
