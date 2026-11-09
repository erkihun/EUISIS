<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use Modules\Feedback\Contracts\Data\ServiceFeedbackType;

final class ComplaintsAnalytics extends PublicVoiceAnalytics
{
    protected function type(): ServiceFeedbackType
    {
        return ServiceFeedbackType::Complaint;
    }

    protected function prefix(): string
    {
        return 'complaints';
    }
}
