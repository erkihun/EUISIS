<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Work experience notices: submitted (to the office), and accepted /
 * returned / sent (to the employee). One notice per step, sent after the
 * transaction commits; a delivery failure never undoes a decision.
 *
 * The stored payload holds the kind and the reference only, so the bell
 * renders it in the READER's language (see render()).
 */
class WorkExperienceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<int, string> $channels */
    public function __construct(
        public readonly string $kind,
        public readonly string $requestId,
        public readonly string $referenceNumber,
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

        return [...$data, ...self::render($data, null), 'url' => $this->path(false)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render($this->data(), null);

        return (new MailMessage)
            ->subject($text['title'])
            ->line($text['message'])
            ->action(__('work_experience::messages.notifications.action'), $this->path(true));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = (string) ($data['kind'] ?? '');
        $params = ['reference' => (string) ($data['reference_number'] ?? '')];

        return [
            'title' => (string) __("work_experience::messages.notifications.{$kind}_subject", $params, $locale),
            'message' => (string) __("work_experience::messages.notifications.{$kind}_body", $params, $locale),
        ];
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['module' => 'work_experience', 'kind' => $this->kind, 'request_id' => $this->requestId, 'reference_number' => $this->referenceNumber];
    }

    private function path(bool $absolute): string
    {
        return $this->kind === 'submitted'
            ? route('work-experience.requests.index', ['request' => $this->requestId], $absolute)
            : route('employee.work-experience.index', ['request' => $this->requestId], $absolute);
    }
}
