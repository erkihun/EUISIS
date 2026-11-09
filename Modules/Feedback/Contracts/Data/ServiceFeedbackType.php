<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts\Data;

enum ServiceFeedbackType: string
{
    case Feedback = 'feedback';
    case Suggestion = 'suggestion';
    case Complaint = 'complaint';
}
