<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A case was filed at the administrative tribunal against the reader's
 * institution (court-ms Flow 7, ADR 0013 #14): `assigned` when it was given to
 * the reader to follow, `unassigned` when the institution has no officer named
 * and a tribunal officer should take it. Rendered at read time in the reader's
 * language by NotificationPresenter; it names the case number only.
 */
class InstitutionCourtCaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $caseNumber,
        public string $url,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['module' => 'institution_court_case', 'kind' => $this->kind, 'case_number' => $this->caseNumber, 'url' => $this->url];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = ($data['kind'] ?? '') === 'unassigned' ? 'unassigned' : 'assigned';
        $case = (string) ($data['case_number'] ?? '');

        return [
            'title' => (string) __("notifications.events.institution_court_case_{$kind}.title", [], $locale),
            'message' => (string) __("notifications.events.institution_court_case_{$kind}.body", ['case' => $case], $locale),
        ];
    }
}
