<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use Modules\Feedback\Contracts\Data\ServiceFeedbackType;

final class TipOffAnalytics extends PublicVoiceAnalytics
{
    protected function type(): ServiceFeedbackType
    {
        return ServiceFeedbackType::Suggestion;
    }

    protected function prefix(): string
    {
        return 'tip_offs';
    }
}
