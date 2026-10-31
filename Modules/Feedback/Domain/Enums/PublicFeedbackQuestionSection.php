<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Enums;

/**
 * Where a questionnaire question appears on the public form: the service
 * experience step (the rating items of the paper form), or with the
 * type-specific details (open questions such as "what should be improved").
 */
enum PublicFeedbackQuestionSection: string
{
    case Experience = 'experience';
    case Details = 'details';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
