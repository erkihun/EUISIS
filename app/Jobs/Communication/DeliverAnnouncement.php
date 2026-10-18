<?php

declare(strict_types=1);

namespace App\Jobs\Communication;

use App\Contracts\SmsGateway;
use App\Enums\Communication\AnnouncementPriority;
use App\Models\Communication\Announcement;
use App\Models\User;
use App\Notifications\Communication\CommunicationNotification;
use App\Services\Communication\AnnouncementAudience;
use App\Services\Communication\CommunicationInbox;
use App\Services\Communication\CommunicationSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Tells the audience about a newly published announcement.
 *
 * - Every reader sees it in their feed and badge without any row per person.
 * - Important, urgent and emergency announcements, and any that need an
 *   acknowledgement, also send an in-app notification.
 * - Urgent and emergency ones are emailed when the system allows it, and
 *   emergencies go by SMS when allowed (through the budgeted SMS gateway).
 */
class DeliverAnnouncement implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public string $announcementId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return $this->announcementId;
    }

    public function handle(AnnouncementAudience $audience, CommunicationSettings $settings, CommunicationInbox $inbox, SmsGateway $sms): void
    {
        $announcement = Announcement::query()->find($this->announcementId);
        if ($announcement === null || ! $announcement->isLive()) {
            return;
        }
        $recipients = $audience->recipients($announcement);
        $announcement->forceFill(['target_count' => (clone $recipients)->count(), 'target_count_computed_at' => now()])->saveQuietly();

        $priority = $announcement->priority;
        $kind = $announcement->acknowledgement_required ? 'announcement_ack_required'
            : ($priority->usesExternalChannels() ? 'announcement_urgent' : ($priority->notifiesImmediately() ? 'announcement_important' : null));
        $channels = ['database', ...($priority->usesExternalChannels() && $settings->emailUrgentAnnouncements() ? ['mail'] : [])];
        $text = ['title' => $announcement->title, 'title_am' => $announcement->title_am ?? $announcement->title];
        $url = '/communication/announcements/'.$announcement->getKey();
        $texting = $priority === AnnouncementPriority::Emergency && $settings->smsEmergencyAnnouncements();

        $recipients->select('users.id', 'users.name', 'users.email', 'users.employee_id')->orderBy('users.id')
            ->chunkById(500, function (Collection $users) use ($kind, $channels, $text, $url, $texting, $sms, $inbox, $announcement): void {
                $inbox->forgetTotals(...$users->modelKeys());
                if ($kind !== null) {
                    Notification::send($users, new CommunicationNotification($kind, $text, $url, $channels));
                }
                if ($texting) {
                    $message = Str::limit($announcement->title_am ?: $announcement->title, 140);
                    $phones = \App\Models\Employee::query()->whereIn('id', $users->pluck('employee_id')->filter())->whereNotNull('phone')->pluck('phone');
                    foreach ($phones as $phone) {
                        $sms->send((string) $phone, $message);
                    }
                }
            }, 'users.id', 'id');
    }
}
