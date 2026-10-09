<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Field Work workflow notices: approval_required (to the supervisor) and
 * approved / returned / rejected (to the requester). One notice per
 * transition, sent after the transaction commits; a delivery failure never
 * rolls a decision back. Queued, like the other module notifications.
 *
 * The stored payload holds the kind and parameters only, so the bell renders
 * it in the READER's language (see render()).
 */
class FieldWorkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<int, string> $channels */
    public function __construct(
        public readonly string $kind,
        public readonly string $requestId,
        public readonly string $referenceNumber,
        public readonly ?string $comment,
        public readonly array $channels,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $data = $this->data();

        return [
            ...$data,
            ...self::render($data, null),
            'url' => $this->path(false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render($this->data(), null);

        return (new MailMessage)
            ->subject($text['title'])
            ->line($text['message'])
            ->action(__('field-work.notifications.action'), $this->path(true));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = (string) ($data['kind'] ?? '');
        $params = ['reference' => (string) ($data['reference_number'] ?? ''), 'comment' => (string) ($data['comment'] ?? '')];

        return [
            'title' => (string) __("field-work.notifications.{$kind}_subject", $params, $locale),
            'message' => (string) __("field-work.notifications.{$kind}_body", $params, $locale),
        ];
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'module' => 'field_work',
            'kind' => $this->kind,
            'request_id' => $this->requestId,
            'reference_number' => $this->referenceNumber,
            'comment' => $this->comment,
        ];
    }

    private function path(bool $absolute): string
    {
        return $this->kind === 'approval_required'
            ? route('field-work.requests.show', $this->requestId, $absolute)
            : route('employee.field-work.show', $this->requestId, $absolute);
    }
}
