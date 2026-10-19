<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Services\SituationRoom\SituationLabels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

/**
 * The words of the Intelligence pages: the Situation Room dictionary (the
 * panel kit's reasons, units and ui strings) plus lang/{en,am}/intelligence.php,
 * intelligence_business.php and intelligence_system.php, flattened under the
 * `intelligence.` prefix. The page's `useSituationLabels()` resolves both
 * families from the same `labels` prop, so a panel built by an Intelligence
 * analysis renders with the Situation Room components unchanged.
 */
final class IntelligenceLabels
{
    public const FILES = ['intelligence', 'intelligence_business', 'intelligence_system'];

    /** @return array{en: array<string, string>, am: array<string, string>} */
    public static function dictionary(): array
    {
        $base = SituationLabels::dictionary();

        return [
            'en' => [...$base['en'], ...self::flat('en')],
            'am' => [...$base['am'], ...self::flat('am')],
        ];
    }

    /** @return array<string, string> */
    private static function flat(string $locale): array
    {
        $out = [];
        foreach (self::FILES as $file) {
            if (! Lang::has($file, $locale, false) && ! is_array(Lang::get($file, [], $locale))) {
                continue;
            }
            $tree = Lang::get($file, [], $locale);
            if (! is_array($tree)) {
                continue;
            }
            foreach (Arr::dot($tree) as $key => $value) {
                if (is_scalar($value)) {
                    $out[IntelligenceAnalysis::PREFIX.$key] = (string) $value;
                    // Notice reasons are looked up by the panel kit without a prefix.
                    if (str_starts_with((string) $key, 'reasons.')) {
                        $out[(string) $key] = (string) $value;
                    }
                }
            }
        }

        return $out;
    }
}
