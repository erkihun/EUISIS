<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\ServiceFeedbackType;

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
