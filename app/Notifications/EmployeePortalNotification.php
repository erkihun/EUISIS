<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeePortalNotification extends Notification
{
    public function __construct(public string $kind, public array $channels) {}

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
