<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base of the module's notices (reminders, returns, verification rejections,
 * clarification replies). Queued; one class per kind. The stored payload
 * holds the kind and its parameters only, so the bell renders it in the
 * READER's language (render(), registered with NotificationPresenter).
 */
abstract class ServiceMonitoringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $params
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly array $params,
        public readonly string $routeName,
        public readonly array $routeParameters,
        public readonly array $channels,
    ) {}

    abstract public function kind(): string;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $data = ['module' => 'service_monitoring', 'kind' => $this->kind(), 'params' => $this->params];

        return [...$data, ...self::render($data, null), 'url' => route($this->routeName, $this->routeParameters, false)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render(['kind' => $this->kind(), 'params' => $this->params], null);

        return (new MailMessage)->subject($text['title'])->line($text['message'])
            ->action((string) __('service-monitoring.notifications.action'), route($this->routeName, $this->routeParameters));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = (string) ($data['kind'] ?? '');
        $params = array_map('strval', (array) ($data['params'] ?? []));

        return [
            'title' => (string) __("service-monitoring.notifications.{$kind}_subject", $params, $locale),
            'message' => (string) __("service-monitoring.notifications.{$kind}_body", $params, $locale),
        ];
    }
}
