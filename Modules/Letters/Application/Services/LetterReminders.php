<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Enums\AuditEventType;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Enums\RoutingStatus;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;

/**
 * Event-driven follow-up instead of passive queues (docs/letters-research.md
 * §2, §6): a routing step is chased two days before its due date and every day
 * once overdue; a letter awaiting receipt for more than two days chases the
 * recipient's registrars; a draft awaiting signature for more than three days
 * chases its signatory. From the institution's reply-time standard: when an
 * open letter's due date is within the interim-reply window and no reply has
 * been started, the holders are told to send an interim reply; when a letter
 * is overdue by more than the escalation threshold, the institution's
 * signatories (the head) are told once. Each row is reminded at most once a day.
 */
class LetterReminders
{
    public const DUE_SOON_DAYS = 2;

    public const RECEIPT_AFTER_DAYS = 2;

    public const SIGNATURE_AFTER_DAYS = 3;

    public function __construct(
        private readonly LetterNotifier $notifier,
        private readonly LetterEvents $events,
        private readonly LetterStandards $standards,
        private readonly LettersAudit $audit,
    ) {}

    /** @return array<string, int> */
    public function run(bool $dryRun = false): array
    {
        $today = now()->startOfDay();
        $counts = ['routing_due' => 0, 'routing_overdue' => 0, 'awaiting_receipt' => 0, 'awaiting_approval' => 0, 'interim_reply_due' => 0, 'escalated' => 0];

        LetterRouting::query()->with(['letter.registrations', 'registration', 'toUser'])
            ->whereIn('status', [RoutingStatus::Pending->value, RoutingStatus::Acknowledged->value])
            ->whereNotNull('to_user_id')->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $today->copy()->addDays(self::DUE_SOON_DAYS))
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhereDate('reminded_at', '<', $today))
            ->chunkById(200, function ($routings) use ($today, $dryRun, &$counts): void {
                foreach ($routings as $routing) {
                    /** @var LetterRouting $routing */
                    $kind = $routing->due_on->lt($today) ? LetterNotification::ROUTING_OVERDUE : LetterNotification::ROUTING_DUE;
                    $counts[$kind]++;
                    if ($dryRun || $routing->toUser === null || $routing->letter === null) {
                        continue;
                    }
                    $this->notifier->send([$routing->toUser], new LetterNotification($kind, [...$this->notifier->params($routing->letter), 'due' => $routing->due_on->toDateString()], ['letter' => $routing->letter_id]));
                    $routing->forceFill(['reminded_at' => now()])->save();
                    $this->events->record($routing->letter, LetterEventType::ReminderSent, null, ['kind' => $kind, 'step' => $routing->step], $routing->registration);
                }
            });

        LetterRegistration::query()->with('letter.registrations')
            ->where('status', RegistrationStatus::PendingReceipt->value)
            ->where('created_at', '<=', now()->subDays(self::RECEIPT_AFTER_DAYS))
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhereDate('reminded_at', '<', $today))
            ->chunkById(200, function ($registrations) use ($dryRun, &$counts): void {
                foreach ($registrations as $registration) {
                    /** @var LetterRegistration $registration */
                    $counts['awaiting_receipt']++;
                    if ($dryRun || $registration->letter === null) {
                        continue;
                    }
                    $this->notifier->send($this->notifier->usersFor('letters.register', $registration->organization_id), new LetterNotification(LetterNotification::AWAITING_RECEIPT, $this->notifier->params($registration->letter), ['letter' => $registration->letter_id]));
                    $registration->forceFill(['reminded_at' => now()])->save();
                    $this->events->record($registration->letter, LetterEventType::ReminderSent, null, ['kind' => 'awaiting_receipt'], $registration);
                }
            });

        Letter::query()->with(['signatory', 'registrations'])
            ->where('status', LetterStatus::PendingApproval->value)
            ->where('updated_at', '<=', now()->subDays(self::SIGNATURE_AFTER_DAYS))
            ->whereDoesntHave('events', fn ($q) => $q->where('type', LetterEventType::ReminderSent->value)->whereDate('created_at', '>=', $today))
            ->chunkById(200, function ($letters) use ($dryRun, &$counts): void {
                foreach ($letters as $letter) {
                    /** @var Letter $letter */
                    $counts['awaiting_approval']++;
                    if ($dryRun) {
                        continue;
                    }
                    $recipients = $letter->signatory_user_id !== null ? collect([$letter->signatory]) : $this->notifier->usersFor('letters.approve', $letter->organization_id);
                    $this->notifier->send($recipients, new LetterNotification(LetterNotification::AWAITING_APPROVAL, $this->notifier->params($letter), ['letter' => $letter->id]));
                    $this->events->record($letter, LetterEventType::ReminderSent, null, ['kind' => 'awaiting_approval']);
                }
            });

        // Reply-time standard: interim reply and escalation on open incoming registry entries with a due date.
        LetterRegistration::query()->with(['letter.registrations', 'letter.replies', 'routings.toUser'])
            ->whereIn('status', [RegistrationStatus::Registered->value, RegistrationStatus::Routed->value, RegistrationStatus::InProgress->value])
            ->where('direction', 'incoming')->whereNotNull('due_on')
            ->chunkById(200, function ($registrations) use ($today, $dryRun, &$counts): void {
                foreach ($registrations as $registration) {
                    /** @var LetterRegistration $registration */
                    $letter = $registration->letter;
                    if ($letter === null) {
                        continue;
                    }
                    $settings = $this->standards->for($registration->organization_id);
                    $due = $registration->due_on->startOfDay();

                    // Interim reply: the date is near, no reply has been started, not reminded today.
                    $interimFrom = $due->copy()->subDays((int) $settings['interim_reply_days']);
                    if ($today->gte($interimFrom) && $today->lte($due) && $letter->replies->isEmpty()
                        && ($registration->interim_reminded_at === null || $registration->interim_reminded_at->lt($today))) {
                        $counts['interim_reply_due']++;
                        if (! $dryRun) {
                            $holders = $registration->routings->filter(fn (LetterRouting $r): bool => $r->status->isOpen() && $r->toUser !== null)->map(fn (LetterRouting $r) => $r->toUser);
                            $recipients = $holders->isNotEmpty() ? $holders : $this->notifier->usersFor('letters.route', $registration->organization_id);
                            $this->notifier->send($recipients, new LetterNotification(LetterNotification::INTERIM_REPLY_DUE, [...$this->notifier->params($letter), 'due' => $due->toDateString()], ['letter' => $letter->id]));
                            $registration->forceFill(['interim_reminded_at' => now()])->save();
                            $this->events->record($letter, LetterEventType::InterimReplyDue, null, ['due' => $due->toDateString()], $registration);
                        }
                    }

                    // Escalation: overdue by more than the threshold, told to the head once.
                    if ($registration->escalated_at === null && $today->gt($due->copy()->addDays((int) $settings['escalation_after_days']))) {
                        $counts['escalated']++;
                        if (! $dryRun) {
                            $this->notifier->send($this->notifier->usersFor('letters.approve', $registration->organization_id), new LetterNotification(LetterNotification::ESCALATED, [...$this->notifier->params($letter), 'due' => $due->toDateString()], ['letter' => $letter->id]));
                            $registration->forceFill(['escalated_at' => now()])->save();
                            $this->events->record($letter, LetterEventType::Escalated, null, ['due' => $due->toDateString()], $registration);
                            $this->audit->record(AuditEventType::LetterEscalated, null, $letter, $registration->organization_id, ['number' => $registration->registry_number, 'due' => $due->toDateString()]);
                        }
                    }
                }
            });

        return $counts;
    }
}
