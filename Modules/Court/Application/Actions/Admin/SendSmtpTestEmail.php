<?php

declare(strict_types=1);

namespace Modules\Court\Application\Actions\Admin;

use Illuminate\Contracts\Mail\Factory;
use Modules\Court\Application\Mail\SmtpDiagnosticMail;

final readonly class SendSmtpTestEmail
{
    public function __construct(private Factory $mail) {}

    public function execute(string $recipient): void
    {
        $message = (new SmtpDiagnosticMail(now()->toDateTimeString()))->to($recipient);

        $this->mail->mailer()->send($message);
    }
}
