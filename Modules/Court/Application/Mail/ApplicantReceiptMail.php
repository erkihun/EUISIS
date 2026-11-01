<?php

namespace Modules\Court\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicantReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public $case;
    protected $pdfBinary;

    public function __construct($case, string $pdfBinary)
    {
        $this->case = $case;
        $this->pdfBinary = $pdfBinary;
    }

    public function build()
    {
        $filename = 'receipt-' . ($this->case->case_number ?? 'case') . '.pdf';

        return $this->subject(__('court::notifications.mail.filing_receipt_subject', [
            'case' => $this->case->case_number ?? '',
        ]))
            ->view('court::mail.applicant_receipt')
            ->attachData($this->pdfBinary, $filename, ['mime' => 'application/pdf']);
    }
}
