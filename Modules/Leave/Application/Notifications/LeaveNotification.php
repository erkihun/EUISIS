<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Leave workflow notices: approval_required (to the supervisor),
 * confirmation_required (to HR officers) and approved / rejected / cancelled
 * / ended_early (to the employee). One notice per transition, sent after the
 * transaction commits; a delivery failure never rolls a decision back.
 *
 * The stored payload holds the kind and parameters only, so the bell renders
 * it in the READER's language (see render()). It never carries the reason of
 * a leave: that may be health data.
 */
class LeaveNotification extends Notification implements ShouldQueue
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
            ->action(__('leave::messages.notifications.action'), $this->path(true));
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
            'title' => (string) __("leave::messages.notifications.{$kind}_subject", $params, $locale),
            'message' => (string) __("leave::messages.notifications.{$kind}_body", $params, $locale),
        ];
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['module' => 'leave', 'kind' => $this->kind, 'request_id' => $this->requestId, 'reference_number' => $this->referenceNumber];
    }

    private function path(bool $absolute): string
    {
        // Notices about the leave schedule open the schedule, not a request.
        if (str_starts_with($this->kind, 'plan_')) {
            return $this->kind === 'plan_submitted' ? route('leave.plans.index', [], $absolute) : route('employee.leave.index', [], $absolute);
        }

        return in_array($this->kind, ['approval_required', 'confirmation_required'], true)
            ? route('leave.requests.show', $this->requestId, $absolute)
            : route('employee.leave.show', $this->requestId, $absolute);
    }
}
