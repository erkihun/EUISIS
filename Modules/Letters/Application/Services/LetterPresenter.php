<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Models\User;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterAttachment;
use Modules\Letters\Domain\Models\LetterEvent;
use Modules\Letters\Domain\Models\LetterRecipient;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;

/**
 * Shapes letters for the Inertia pages: list rows and the full letter page.
 * A confidential or secret letter shows its subject, text, copies and files
 * only to the people who may read them (LettersAccess::canViewContents); the
 * registry row still shows the number, dates, institution and status.
 */
class LetterPresenter
{
    public function __construct(
        private readonly LettersAccess $access,
        private readonly TemplateRenderer $renderer,
        private readonly LetterVerification $verification,
    ) {}

    /** @return array<string, mixed> */
    public function row(Letter $letter, ?LetterRegistration $registration = null, ?User $viewer = null): array
    {
        $registration ??= $letter->ownRegistration();
        $readable = $viewer === null || ! $letter->isRestricted() || $this->access->canViewContents($viewer, $letter->loadMissing(['registrations', 'routings']));

        return [
            'id' => $letter->id,
            'registration_id' => $registration?->id,
            'direction' => $registration?->direction->value ?? $letter->direction->value,
            'status' => $letter->status->value,
            'registration_status' => $registration?->status->value,
            'number' => $registration?->registry_number,
            'subject' => $readable ? $letter->subject : null,
            'classification' => $letter->classification->value,
            'file_code' => $letter->file_code,
            'file_class' => $letter->file_class_id !== null && $letter->relationLoaded('fileClass') && $letter->fileClass !== null ? ['code' => $letter->fileClass->code, 'title_am' => $letter->fileClass->title_am, 'title_en' => $letter->fileClass->title_en] : null,
            'priority' => $letter->priority->value,
            'letter_date' => $letter->letter_date?->toDateString(),
            'received_on' => $registration?->received_on?->toDateString(),
            'due_on' => $registration?->due_on?->toDateString(),
            'organization' => $this->named($registration?->organization ?? $letter->organization),
            'counterpart' => $readable || $letter->direction->value !== 'incoming' ? $this->counterpart($letter, $registration) : null,
            'open_routings' => $registration !== null ? $registration->routings->filter(fn (LetterRouting $r): bool => $r->status->isOpen())->count() : 0,
            'created_at' => $letter->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function full(Letter $letter, User $user): array
    {
        $letter->loadMissing([
            'organization:id,code,name_en,name_am', 'unit:id,code,name_en,name_am', 'senderOrganization:id,code,name_en,name_am', 'recipientOrganization:id,code,name_en,name_am',
            'signatory:id,name', 'approver:id,name', 'creator:id,name', 'templateVersion.template:id,code,title_en,title_am',
            'registrations.organization:id,code,name_en,name_am', 'registrations.routings.toUser:id,name', 'registrations.routings.toUnit:id,code,name_en,name_am', 'registrations.routings.fromUser:id,name',
            'attachments.uploader:id,name', 'events.actor:id,name', 'inReplyTo', 'supersedes', 'replies', 'copies.organization:id,code,name_en,name_am', 'routings', 'fileClass', 'letterhead:id,name',
        ]);
        $readable = $this->access->canViewContents($user, $letter);
        $locale = $letter->language === 'en' ? 'en' : 'am';
        $context = $readable && $letter->isComposed() ? $this->renderer->context($letter, $locale) : [];

        return [
            'id' => $letter->id,
            'direction' => $letter->direction->value,
            'status' => $letter->status->value,
            'classification' => $letter->classification->value,
            'contents_hidden' => ! $readable,
            'subject' => $readable ? ($letter->isComposed() ? $this->renderer->render($letter->subject, $context) : $letter->subject) : null,
            'raw_subject' => $readable ? $letter->subject : null,
            'summary' => $readable ? $letter->summary : null,
            'body' => $readable && $letter->isComposed() ? $this->renderer->render($letter->body, $context) : null,
            'raw_body' => $readable ? $letter->body : null,
            'language' => $letter->language,
            'priority' => $letter->priority->value,
            'file_code' => $letter->file_code,
            'file_class' => $letter->fileClass !== null ? ['id' => $letter->fileClass->id, 'code' => $letter->fileClass->code, 'title_am' => $letter->fileClass->title_am, 'title_en' => $letter->fileClass->title_en] : null,
            'letterhead' => $letter->letterhead !== null ? ['id' => $letter->letterhead->id, 'name' => $letter->letterhead->name] : null,
            'letter_date' => $letter->letter_date?->toDateString(),
            'external_party_name' => $readable ? $letter->external_party_name : null,
            'external_party_address' => $readable ? $letter->external_party_address : null,
            'external_party_email' => $readable ? $letter->external_party_email : null,
            'external_reference' => $readable ? $letter->external_reference : null,
            'organization' => $this->named($letter->organization) + ['id' => $letter->organization_id],
            'unit' => $letter->unit !== null ? $this->named($letter->unit) + ['id' => $letter->unit_id] : null,
            'sender_organization' => $letter->senderOrganization !== null ? $this->named($letter->senderOrganization) + ['id' => $letter->sender_organization_id] : null,
            'recipient_organization' => $letter->recipientOrganization !== null ? $this->named($letter->recipientOrganization) + ['id' => $letter->recipient_organization_id] : null,
            'counterpart' => $readable || $letter->direction->value !== 'incoming' ? $this->counterpart($letter, null) : null,
            'copies' => $readable ? $letter->copies->map(fn (LetterRecipient $c): array => [
                'id' => $c->id, 'organization_id' => $c->organization_id, 'organization' => $c->organization !== null ? $this->named($c->organization) : null,
                'external_name' => $c->external_name, 'external_address' => $c->external_address,
            ])->values()->all() : [],
            'number' => $letter->referenceNumber(),
            'signatory' => $letter->signatory !== null ? ['id' => $letter->signatory->id, 'name' => $letter->signatory->name] : null,
            'approved_by' => $letter->approver?->name,
            'approved_at' => $letter->approved_at?->toIso8601String(),
            'dispatched_at' => $letter->dispatched_at?->toIso8601String(),
            'dispatch_mode' => $letter->dispatch_mode?->value,
            'dispatch_reference' => $letter->dispatch_reference,
            'dispatch_note' => $readable ? $letter->dispatch_note : null,
            'verification_code' => $letter->verification_code,
            'verification_url' => $this->verification->url($letter),
            'created_by' => $letter->creator?->name,
            'created_at' => $letter->created_at?->toIso8601String(),
            'template' => $readable && $letter->templateVersion !== null ? [
                'code' => $letter->templateVersion->template?->code, 'title_en' => $letter->templateVersion->template?->title_en, 'title_am' => $letter->templateVersion->template?->title_am,
                'version_no' => $letter->templateVersion->version_no, 'placeholders' => $letter->templateVersion->placeholderList(),
            ] : null,
            'template_context' => $readable ? ($letter->template_context ?? (object) []) : (object) [],
            'regarding' => $letter->regarding_type !== null ? ['type' => $letter->regarding_type, 'id' => $letter->regarding_id] : null,
            'in_reply_to' => $letter->inReplyTo !== null ? ['id' => $letter->inReplyTo->id, 'subject' => $readable ? $letter->inReplyTo->subject : null] : null,
            'supersedes' => $letter->supersedes !== null ? ['id' => $letter->supersedes->id, 'subject' => $readable ? $letter->supersedes->subject : null] : null,
            'replies' => $readable ? $letter->replies->map(fn (Letter $r): array => ['id' => $r->id, 'subject' => $r->subject, 'status' => $r->status->value])->values()->all() : [],
            'registrations' => $letter->registrations->map(fn (LetterRegistration $r): array => $this->registration($r, $user))->values()->all(),
            'attachments' => $readable ? $letter->attachments->map(fn (LetterAttachment $a): array => [
                'id' => $a->id, 'kind' => $a->kind->value, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
                'uploaded_by' => $a->uploader?->name, 'created_at' => $a->created_at?->toIso8601String(),
            ])->values()->all() : [],
            'events' => $letter->events->map(fn (LetterEvent $e): array => [
                'id' => $e->id, 'type' => $e->type->value, 'actor' => $e->actor?->name,
                'payload' => $readable ? ($e->payload ?? (object) []) : (object) array_intersect_key($e->payload ?? [], array_flip(['number', 'step', 'mode'])),
                'at' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
            'can' => [
                'update' => $user->can('update', $letter),
                'submit' => $user->can('submit', $letter),
                'approve' => $user->can('approve', $letter),
                'return' => $user->can('return', $letter),
                'dispatch' => $user->can('dispatch', $letter),
                'cancel' => $user->can('cancel', $letter),
                'reply' => $readable && $user->can('reply', $letter),
                'add_attachment' => $readable && $user->can('addAttachment', $letter),
                'pdf' => $user->can('pdf', $letter),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function registration(LetterRegistration $registration, User $user): array
    {
        return [
            'id' => $registration->id,
            'organization' => $this->named($registration->organization) + ['id' => $registration->organization_id],
            'direction' => $registration->direction->value,
            'number' => $registration->registry_number,
            'status' => $registration->status->value,
            'registered_at' => $registration->registered_at?->toIso8601String(),
            'received_on' => $registration->received_on?->toDateString(),
            'due_on' => $registration->due_on?->toDateString(),
            'physical_location' => $registration->physical_location,
            'acknowledged_at' => $registration->acknowledged_at?->toIso8601String(),
            'acknowledgement_note' => $registration->acknowledgement_note,
            'escalated_at' => $registration->escalated_at?->toIso8601String(),
            'closed_at' => $registration->closed_at?->toIso8601String(),
            'closing_note' => $registration->closing_note,
            'routings' => $registration->routings->map(fn (LetterRouting $r): array => [
                'id' => $r->id, 'step' => $r->step, 'status' => $r->status->value, 'instruction' => $r->instruction, 'due_on' => $r->due_on?->toDateString(),
                'from' => $r->fromUser?->name, 'to_user' => $r->toUser !== null ? ['id' => $r->toUser->id, 'name' => $r->toUser->name] : null,
                'to_unit' => $r->toUnit !== null ? $this->named($r->toUnit) + ['id' => $r->to_unit_id] : null,
                'acknowledged_at' => $r->acknowledged_at?->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String(), 'outcome' => $r->outcome,
                'from_unit' => $r->fromUnit !== null ? $this->named($r->fromUnit) + ['id' => $r->from_unit_id] : null,
                'parent_step' => $r->parent_id !== null ? $registration->routings->firstWhere('id', $r->parent_id)?->step : null,
                'can_act' => $this->access->canActOnRouting($user, $r),
                'can_forward' => $this->access->canForwardOnward($user, $r->setRelation('registration', $registration)),
            ])->values()->all(),
            'can' => [
                'receive' => $this->access->canReceive($user, $registration),
                'route' => $this->access->canRoute($user, $registration),
                'close' => $this->access->canClose($user, $registration),
                'acknowledge' => $this->access->canAcknowledge($user, $registration),
            ],
        ];
    }

    /** @return array{name_en: string|null, name_am: string|null, code?: string|null} */
    private function named(mixed $model): array
    {
        return ['name_en' => $model?->name_en, 'name_am' => $model?->name_am, 'code' => $model?->code ?? null];
    }

    /** The other party, as the owning institution sees it. */
    private function counterpart(Letter $letter, ?LetterRegistration $registration): ?array
    {
        $viewer = $registration?->organization_id ?? $letter->organization_id;
        if ($letter->direction->value === 'internal') {
            return $letter->unit !== null ? $this->named($letter->unit) : null;
        }
        if ($letter->direction->value === 'incoming') {
            return $letter->senderOrganization !== null ? $this->named($letter->senderOrganization) : ['name_en' => $letter->external_party_name, 'name_am' => $letter->external_party_name, 'code' => null];
        }
        // Outgoing: the recipient's registry shows the sender, the sender's shows the recipient.
        if ($viewer !== $letter->organization_id) {
            return $this->named($letter->organization);
        }

        return $letter->recipientOrganization !== null ? $this->named($letter->recipientOrganization) : ['name_en' => $letter->external_party_name, 'name_am' => $letter->external_party_name, 'code' => null];
    }
}
