<?php

namespace Modules\Court\Application\Mail;

use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Letter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class LetterApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Letter $letter,
        public ?CourtCase $case = null,
        public ?string $recipientName = null
    ) {
    }

    public function build(): static
    {
        $caseNumber = $this->letter->case_number ?? $this->case?->case_number ?? '';
        $subject = trim('Approved Letter - ' . ($caseNumber ?: ($this->letter->subject ?? '')));

        $previewUrl = Route::has('court.letters.case-preview')
            ? URL::signedRoute('court.letters.case-preview', ['letter' => $this->letter->getKey()])
            : URL::to('/court/case-letters/' . $this->letter->getKey());

        return $this->subject($subject)
            ->view('court::mail.letter-approved')
            ->with([
                'letter'        => $this->letter,
                'case'          => $this->case,
                'caseNumber'    => $caseNumber,
                'recipientName' => $this->recipientName,
                'previewUrl'    => $previewUrl,
            ]);
    }
}
