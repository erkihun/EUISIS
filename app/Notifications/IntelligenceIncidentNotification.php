<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\IntelligenceIncident;
use App\Services\Branding\BrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * A technical incident was opened or escalated. The notification carries the
 * reference, severity, component and a link — never evidence, a stack trace
 * or a log line (brief §67).
 */
class IntelligenceIncidentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $channels */
    public function __construct(
        public readonly string $reference,
        public readonly string $severity,
        public readonly string $component,
        public readonly string $event,
        public readonly string $url,
        private readonly array $channels,
    ) {
        $this->onQueue('notifications');
    }

    public static function for(IntelligenceIncident $incident, string $event, array $channels): self
    {
        $url = Route::has('intelligence.incidents.show') ? route('intelligence.incidents.show', $incident->id) : url('/intelligence/incidents/'.$incident->id);

        return new self($incident->reference, $incident->severity, $incident->component, $event, $url, $channels);
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('intelligence.notifications.'.$this->event.'.title', ['reference' => $this->reference, 'severity' => __('intelligence.severity.'.$this->severity)]),
            'message' => __('intelligence.notifications.'.$this->event.'.message', ['component' => $this->component]),
            'url' => $this->url,
            'reference' => $this->reference,
            'severity' => $this->severity,
            'component' => $this->component,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(app(BrandingService::class)->brandName().' · '.__('intelligence.notifications.'.$this->event.'.title', ['reference' => $this->reference, 'severity' => __('intelligence.severity.'.$this->severity)]))
            ->line(__('intelligence.notifications.'.$this->event.'.message', ['component' => $this->component]))
            ->action(__('intelligence.notifications.open'), $this->url);
    }
}
