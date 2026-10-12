<?php

declare(strict_types=1);

namespace App\Enums;

enum ServiceFeedbackType: string
{
    case Feedback = 'feedback';
    case Suggestion = 'suggestion';
    case Complaint = 'complaint';
}
