<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\IntelligenceAlert;
use App\Services\Branding\BrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A new business alert for executives: severity, area and a link — figures and evidence stay on the page. */
class IntelligenceAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $channels */
    public function __construct(
        public readonly string $alertId,
        public readonly string $severity,
        public readonly string $area,
        public readonly string $url,
        private readonly array $channels,
    ) {
        $this->onQueue('notifications');
    }

    public static function for(IntelligenceAlert $alert, array $channels): self
    {
        return new self($alert->id, $alert->severity, (string) $alert->area, route('intelligence.alerts.index'), $channels);
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('intelligence.notifications.alert.title', ['severity' => __('intelligence.severity.'.$this->severity)]),
            'message' => __('intelligence.notifications.alert.message', ['area' => __('intelligence.areas_short.'.$this->area)]),
            'url' => $this->url,
            'alert_id' => $this->alertId,
            'severity' => $this->severity,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(app(BrandingService::class)->brandName().' · '.__('intelligence.notifications.alert.title', ['severity' => __('intelligence.severity.'.$this->severity)]))
            ->line(__('intelligence.notifications.alert.message', ['area' => __('intelligence.areas_short.'.$this->area)]))
            ->action(__('intelligence.notifications.open_alerts'), $this->url);
    }
}
