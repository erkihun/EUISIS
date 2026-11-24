<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The module's notices. Queued; the stored payload holds the kind and its
 * parameters only (period, reference, deadline; never the narrative), so the
 * bell words it in the READER's language through render(), registered with
 * the application's notification presenter under "reporting".
 */
final class ReportingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $params
     * @param  list<string>  $channels  database, and mail when e-mail is switched on
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $params,
        public readonly string $path,
        public readonly array $channels = ['database'],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $data = ['module' => 'reporting', 'kind' => $this->kind, 'params' => $this->params];

        return [...$data, ...self::render($data, null), 'url' => $this->path];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render(['kind' => $this->kind, 'params' => $this->params], null);

        return (new MailMessage)->subject($text['title'])->line($text['message'])
            ->action((string) __('reporting::notifications.action'), url($this->path));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = (string) ($data['kind'] ?? '');
        $params = array_map('strval', (array) ($data['params'] ?? []));
        // Notices carry both language forms of names; the reader's language picks one.
        $language = $locale ?? app()->getLocale();
        foreach (['period', 'type', 'institution'] as $name) {
            if ($language === 'am' && ($params["{$name}_am"] ?? '') !== '') {
                $params[$name] = $params["{$name}_am"];
            }
        }

        return [
            'title' => (string) __("reporting::notifications.{$kind}_subject", $params, $locale),
            'message' => (string) __("reporting::notifications.{$kind}_body", $params, $locale),
        ];
    }
}
