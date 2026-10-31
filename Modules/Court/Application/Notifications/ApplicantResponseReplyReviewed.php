<?php

namespace Modules\Court\Application\Notifications;

use Modules\Court\Domain\Models\ApplicantResponseReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ApplicantResponseReplyReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private ApplicantResponseReply $reply,
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
            'type' => 'response_reply_reviewed',
            'reply_id' => $this->reply->id,
            'case_id' => $this->reply->case_id,
            'respondent_response_id' => $this->reply->respondent_response_id,
            'decision' => $this->decision,
            'note' => $this->note,
            'message' => __('court::notifications.response_reply_reviewed', [
                'status' => __('court::notifications.status.' . $this->decision),
            ]),
        ];
    }
}
