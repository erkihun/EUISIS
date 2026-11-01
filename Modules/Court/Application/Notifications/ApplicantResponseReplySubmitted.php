<?php

namespace Modules\Court\Application\Notifications;

use Modules\Court\Domain\Models\ApplicantResponseReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ApplicantResponseReplySubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private ApplicantResponseReply $reply)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'applicant_response_reply_submitted',
            'reply_id' => $this->reply->id,
            'case_id' => $this->reply->case_id,
            'respondent_response_id' => $this->reply->respondent_response_id,
            'message' => __('court::notifications.response_reply_submitted', [
                'case' => $this->reply->case_id,
            ]),
        ];
    }
}
