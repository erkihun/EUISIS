<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one version of the questionnaire stands. A draft is edited freely; a
 * published version is frozen, because submissions answer exactly its
 * wording; publishing a newer version retires the older one, which stays so
 * historical answers keep their meaning.
 */
enum PublicFeedbackQuestionSetStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
