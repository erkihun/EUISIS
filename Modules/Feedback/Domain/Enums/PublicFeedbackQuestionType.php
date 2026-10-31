<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Enums;

/**
 * The kinds of question the organization feedback questionnaire may ask —
 * only what the paper service-feedback form needs, not a form builder.
 *
 * A rating uses the platform's one five-point satisfaction scale
 * (1 = very dissatisfied … 5 = very satisfied), the same scale as the
 * overall Feedback rating, so answers aggregate on one axis.
 */
enum PublicFeedbackQuestionType: string
{
    case Rating = 'rating';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case YesNo = 'yes_no';

    public const RATING_MIN = 1;

    public const RATING_MAX = 5;

    public const SHORT_TEXT_MAX = 255;

    public const LONG_TEXT_MAX = 2000;

    public function hasOptions(): bool
    {
        return $this === self::SingleChoice || $this === self::MultipleChoice;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
