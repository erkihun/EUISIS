<?php

namespace Modules\Court\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CaseMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public object $caseRow,
        public string $sender,     // "Applicant" or "Court Staff"
        public string $bodyPreview // short preview
    ) {}

    public function build()
    {
        return $this->subject(__('court::notifications.mail.case_message_subject', [
            'case' => $this->caseRow->case_number,
        ]))
            ->view('court::mail.case_message');
    }
}
