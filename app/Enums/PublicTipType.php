<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a public Tip-off (ጥቆማ) reports. Exactly two kinds, chosen by the
 * submitter, stored as these stable codes — never as a translated label.
 *
 * Both are reports of something SUSPECTED or OBSERVED. Neither establishes
 * that corruption or malpractice took place, and neither starts discipline,
 * a sanction or a score change by itself.
 *
 * The internal submission type of a Tip-off is still `suggestion`
 * (ServiceFeedbackType::Suggestion): that value is a production identifier in
 * routes, rows and routing rules. Suggestions stored before Tip-off types
 * existed have no tip type and are never back-filled with one.
 */
enum PublicTipType: string
{
    /** የሙስና ጥቆማ — information about suspected corruption. */
    case Corruption = 'CORRUPTION';

    /** የብልሹ አሠራር ጥቆማ — an observed or suspected improper practice or maladministration. */
    case ImproperPractice = 'IMPROPER_PRACTICE';

    /** Analytics label for legacy suggestions with no tip type. Never stored. */
    public const UNCLASSIFIED_LEGACY = 'UNCLASSIFIED_LEGACY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
