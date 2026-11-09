<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PublicIdCheckOtp;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a card holder that someone is trying to verify their ID card, and
 * carries the code that authorises it.
 *
 * The message deliberately contains no employee data: it goes to the employee,
 * who already knows who they are, and a message that repeated their name or
 * organization would leak that to anyone with access to the inbox or handset.
 * Only the code, the expiry, and the masked card number appear.
 */
class PublicIdCheckOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly string $maskedCardNumber,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // SMS is dispatched separately through SmsGateway, because no Laravel
        // notification channel exists for it yet.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('idChecker.otpMailSubject'))
            ->greeting(__('idChecker.otpMailGreeting'))
            ->line(__('idChecker.otpMailIntro', ['card' => $this->maskedCardNumber]))
            ->line('**'.$this->code.'**')
            ->line(__('idChecker.otpMailExpiry', ['minutes' => PublicIdCheckOtp::TTL_MINUTES]))
            ->line(__('idChecker.otpMailIgnore'));
    }

    /** Short body for the SMS gateway; same no-PII rule. */
    public function toSmsText(): string
    {
        return __('idChecker.otpSmsBody', [
            'code' => $this->code,
            'minutes' => PublicIdCheckOtp::TTL_MINUTES,
        ]);
    }
}
