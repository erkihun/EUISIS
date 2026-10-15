<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\ServiceFeedbackType;

final class FeedbackAnalytics extends PublicVoiceAnalytics
{
    protected function type(): ServiceFeedbackType
    {
        return ServiceFeedbackType::Feedback;
    }

    protected function prefix(): string
    {
        return 'feedback';
    }
}
