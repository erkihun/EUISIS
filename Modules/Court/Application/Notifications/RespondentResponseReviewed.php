<?php

namespace Modules\Court\Application\Notifications;

use Modules\Court\Domain\Models\RespondentResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RespondentResponseReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private RespondentResponse $response,
        private string $decision,
        private ?string $note = null
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'respondent_response_reviewed',
            'response_id' => $this->response->id,
            'case_number' => $this->response->case_number,
            'response_number' => $this->response->response_number,
            'decision' => $this->decision,
            'note' => $this->note,
            'message' => __('court::notifications.respondent_response_reviewed', [
                'status' => __('court::notifications.status.' . $this->decision),
                'case' => $this->response->case_number ?? __('court::notifications.case_unknown'),
            ]),
        ];
    }
}
