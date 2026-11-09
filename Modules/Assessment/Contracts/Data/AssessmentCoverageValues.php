<?php

declare(strict_types=1);

namespace Modules\Assessment\Contracts\Data;

/** Values the assessment coverage reports group by, published for other reports (the Situation Room). */
final class AssessmentCoverageValues
{
    /** The stored spellings of each gender (English and Amharic). */
    public const GENDER_VALUES = ['male' => ['male', 'm', 'ወንድ'], 'female' => ['female', 'f', 'ሴት']];
}
