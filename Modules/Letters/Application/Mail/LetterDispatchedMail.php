<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A dispatched letter sent to a party outside the platform: the frozen PDF
 * attached, the registry number, the verification code and the check page in
 * the text, nothing else of the letter's content in the mail body.
 */
final class LetterDispatchedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $institutionName,
        public readonly string $number,
        public readonly string $subjectLine,
        public readonly string $verificationCode,
        public readonly string $verifyUrl,
        public readonly string $language,
        private readonly string $pdf,
        private readonly string $fileName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('letters.mail.subject', ['institution' => $this->institutionName, 'number' => $this->number], $this->language));
    }

    public function content(): Content
    {
        return new Content(view: 'letters.mail-dispatched', with: [
            'institutionName' => $this->institutionName, 'number' => $this->number, 'subjectLine' => $this->subjectLine,
            'verificationCode' => $this->verificationCode, 'verifyUrl' => $this->verifyUrl, 'locale' => $this->language,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }
}
