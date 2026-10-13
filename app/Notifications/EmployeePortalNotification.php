<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Queued on `notifications` so mail/database delivery never blocks a request. */
class EmployeePortalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public array $channels)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return ['module' => 'employee_portal', 'kind' => $this->kind, 'title' => __('employee-portal.reprint_required'), 'message' => __('employee-portal.'.$this->kind), 'url' => $this->kind === 'officer_notice' ? '/id-cards/reprint-required' : '/my-portal/id-card'];
    }

    /**
     * Title and message in the reader's language (see DailyActivityNotification::render).
     *
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        return [
            'title' => (string) __('employee-portal.reprint_required', [], $locale),
            'message' => (string) __('employee-portal.'.($data['kind'] ?? ''), [], $locale),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('employee-portal.reprint_required'))->line(__('employee-portal.'.$this->kind));
    }
}
