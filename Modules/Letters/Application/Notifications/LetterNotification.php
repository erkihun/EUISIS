<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The module's notices: a letter awaiting receipt, a draft awaiting
 * signature, a returned or signed draft, a routing step addressed to someone.
 * Queued; the stored payload holds the kind and its parameters only, so the
 * bell renders it in the READER's language (render(), registered with
 * NotificationPresenter under 'letters').
 */
final class LetterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const AWAITING_RECEIPT = 'awaiting_receipt';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const RETURNED = 'returned';

    public const APPROVED = 'approved';

    public const ROUTED = 'routed';

    public const ROUTING_DUE = 'routing_due';

    public const ROUTING_OVERDUE = 'routing_overdue';

    public const ESCALATED = 'escalated';

    public const INTERIM_REPLY_DUE = 'interim_reply_due';

    /**
     * @param  array<string, string>  $params
     * @param  array<string, mixed>  $routeParameters
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $params,
        public readonly array $routeParameters,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $data = ['module' => 'letters', 'kind' => $this->kind, 'params' => $this->params];

        return [...$data, ...self::render($data, null), 'url' => route('letters.show', $this->routeParameters, false)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render(['kind' => $this->kind, 'params' => $this->params], null);

        return (new MailMessage)->subject($text['title'])->line($text['message'])
            ->action((string) __('letters.notifications.action'), route('letters.show', $this->routeParameters));
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
            'title' => (string) __("letters.notifications.{$kind}_subject", $params, $locale),
            'message' => (string) __("letters.notifications.{$kind}_body", $params, $locale),
        ];
    }
}
