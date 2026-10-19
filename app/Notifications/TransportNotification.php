<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Transport notices to an employee: a route assignment, a change or
 * suspension of it, a cancelled trip, a service alert. Carries only what
 * the employee needs (route code and name, a date); never another
 * employee's data. Wording is rendered at read time in the reader's language.
 */
class TransportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KINDS = ['route_assigned', 'route_changed', 'assignment_suspended', 'trip_cancelled', 'schedule_changed', 'service_alert'];

    /**
     * @param  list<string>  $channels
     * @param  array<string, string|null>  $context  route_code, route_name_en, route_name_am, date, time, direction, note
     */
    public function __construct(public readonly string $kind, public readonly array $context, public readonly array $channels)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'module' => 'transport',
            'kind' => $this->kind,
            'context' => $this->context,
            'url' => '/my-portal/services',
        ];
    }

    /** @param  array<string, mixed>  $data @return array{title: string, message: string} */
    public static function render(array $data, ?string $locale): array
    {
        $locale = in_array($locale, ['en', 'am'], true) ? $locale : 'en';
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];
        $routeName = $locale === 'am' ? ($context['route_name_am'] ?? $context['route_name_en'] ?? '') : ($context['route_name_en'] ?? $context['route_name_am'] ?? '');
        $kind = in_array($data['kind'] ?? '', self::KINDS, true) ? $data['kind'] : 'service_alert';

        return [
            'title' => (string) __('transport.notifications.title', [], $locale),
            'message' => (string) __('transport.notifications.'.$kind, [
                'route' => trim(($context['route_code'] ?? '').' '.$routeName),
                'date' => (string) ($context['date'] ?? ''),
                'time' => (string) ($context['time'] ?? ''),
                'note' => (string) ($context['note'] ?? ''),
            ], $locale),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render($this->toArray($notifiable), app()->getLocale());

        return (new MailMessage)->subject($text['title'])->line($text['message']);
    }
}
