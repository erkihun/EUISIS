<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupHealthNotification extends Notification
{
    public function __construct(public string $state, public array $issues) {}

    public function via(object $notifiable): array
    {
        return config('backup.alert_mail') ? ['database', 'mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'Backup & Recovery: '.$this->state, 'message' => 'Review backup health and the restricted operations record.',
            'url' => '/system/backup-recovery', 'state' => $this->state, 'issues' => $this->issues];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('EUISIS Backup & Recovery: '.$this->state)
            ->line('Review backup health.')->action('Backup & Recovery', url('/system/backup-recovery'));
    }
}
