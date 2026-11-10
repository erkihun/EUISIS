<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Mail\LetterDispatchedMail;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Application\Services\LetterAttachmentStore;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LetterNotifier;
use Modules\Letters\Application\Services\LetterNumbering;
use Modules\Letters\Application\Services\LetterPdf;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Application\Services\LetterVerification;
use Modules\Letters\Domain\Enums\DispatchMode;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterRegistration;

/**
 * Dispatches an approved letter (ADR 0037 §4, §6): the sender's registry entry
 * is created and numbered, the dispatch register records how it left (mode,
 * tracking reference), a verification code is issued and the rendered PDF is
 * frozen as an attachment. The primary recipient and every copy recipient in
 * the system get a `pending_receipt` entry their registrars will number on
 * receipt. A letter dispatched by email to a party outside the platform is
 * mailed with the PDF, the number and the verification code. From here the
 * letter never changes.
 */
final class DispatchLetter
{
    public function __construct(
        private readonly LetterNumbering $numbering,
        private readonly LetterPdf $pdf,
        private readonly LetterAttachmentStore $attachments,
        private readonly LetterVerification $verification,
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
        private readonly LetterNotifier $notifier,
    ) {}

    /** @param array{mode?: string|null, reference?: string|null, note?: string|null, email?: string|null} $dispatch */
    public function execute(Letter $letter, User $actor, array $dispatch = []): Letter
    {
        $mode = DispatchMode::tryFrom((string) ($dispatch['mode'] ?? 'system')) ?? DispatchMode::System;
        $email = $this->emailFor($letter, $dispatch);
        if ($mode === DispatchMode::Email && $email === null) {
            throw ValidationException::withMessages(['email' => __('letters.validation.email_required')]);
        }

        $letter = DB::transaction(function () use ($letter, $actor, $dispatch, $mode, $email): Letter {
            $own = LetterRegistration::query()->firstOrCreate(
                ['letter_id' => $letter->id, 'organization_id' => $letter->organization_id],
                ['direction' => $letter->direction->value, 'status' => RegistrationStatus::Registered->value, 'registered_at' => now(), 'registered_by' => $actor->id],
            );
            $own->setRelation('letter', $letter);
            $this->numbering->assign($own);

            $letter->forceFill([
                'status' => LetterStatus::Dispatched->value,
                'dispatched_by' => $actor->id,
                'dispatched_at' => now(),
                'dispatch_mode' => $mode->value,
                'dispatch_reference' => $mode === DispatchMode::Email ? $email : (isset($dispatch['reference']) && trim((string) $dispatch['reference']) !== '' ? mb_substr(trim((string) $dispatch['reference']), 0, 120) : null),
                'dispatch_note' => isset($dispatch['note']) && trim((string) $dispatch['note']) !== '' ? mb_substr(trim((string) $dispatch['note']), 0, 500) : null,
                'verification_code' => $letter->verification_code ?? $this->verification->issueCode(),
                'external_party_email' => $email ?? $letter->external_party_email,
                'updated_by' => $actor->id,
            ])->save();
            $letter->setRelation('registrations', $letter->registrations()->get());

            $bytes = $this->pdf->render($letter);
            $this->attachments->storeRendered($bytes, $letter, $this->pdf->fileName($letter), $actor);

            $this->events->record($letter, LetterEventType::NumberAssigned, $actor, ['number' => $own->registry_number], $own);
            $this->events->record($letter, LetterEventType::Dispatched, $actor, ['number' => $own->registry_number, 'mode' => $mode->value, 'reference' => $letter->dispatch_reference], $own);
            $this->audit->record(AuditEventType::LetterDispatched, $actor, $letter, $letter->organization_id, [
                'number' => $own->registry_number, 'subject' => $letter->subject, 'mode' => $mode->value, 'reference' => $letter->dispatch_reference, 'copies' => $letter->copies()->count(),
            ]);

            if ($letter->direction === LetterDirection::Outgoing) {
                $recipients = array_values(array_unique(array_filter([
                    $letter->recipient_organization_id,
                    ...$letter->copies()->whereNotNull('organization_id')->pluck('organization_id')->all(),
                ], fn (?string $id): bool => $id !== null && $id !== $letter->organization_id)));
                foreach ($recipients as $recipient) {
                    $theirs = LetterRegistration::query()->firstOrCreate(
                        ['letter_id' => $letter->id, 'organization_id' => $recipient],
                        ['direction' => LetterDirection::Incoming->value, 'status' => RegistrationStatus::PendingReceipt->value],
                    );
                    $this->events->record($letter, LetterEventType::Routed, $actor, ['to_organization_id' => $recipient, 'awaiting_receipt' => true, 'copy' => $recipient !== $letter->recipient_organization_id], $theirs);
                    $this->notifier->send(
                        $this->notifier->usersFor('letters.register', $recipient),
                        new LetterNotification(LetterNotification::AWAITING_RECEIPT, $this->notifier->params($letter), ['letter' => $letter->id]),
                    );
                }
            }

            if ($mode === DispatchMode::Email && $email !== null) {
                $letter->loadMissing('organization');
                $locale = $letter->language === 'en' ? 'en' : 'am';
                $institution = (string) ($locale === 'am' ? ($letter->organization?->name_am ?? $letter->organization?->name_en) : ($letter->organization?->name_en ?? $letter->organization?->name_am));
                Mail::to($email)->queue(new LetterDispatchedMail(
                    institutionName: $institution, number: (string) $own->registry_number, subjectLine: (string) $letter->subject,
                    verificationCode: (string) $letter->verification_code, verifyUrl: (string) $this->verification->url($letter), language: $locale,
                    pdf: $bytes, fileName: $this->pdf->fileName($letter),
                ));
                $this->events->record($letter, LetterEventType::Emailed, $actor, ['to' => $email], $own);
                $this->audit->record(AuditEventType::LetterEmailed, $actor, $letter, $letter->organization_id, ['number' => $own->registry_number, 'to' => $email]);
            }

            return $letter;
        });

        return $letter->fresh(['registrations', 'attachments']);
    }

    /** The address a letter dispatched by email goes to: the dispatch form's, else the letter's external party email. */
    private function emailFor(Letter $letter, array $dispatch): ?string
    {
        foreach ([$dispatch['email'] ?? null, $dispatch['reference'] ?? null, $letter->external_party_email] as $candidate) {
            if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_EMAIL) !== false) {
                return strtolower(trim($candidate));
            }
        }

        return null;
    }
}
