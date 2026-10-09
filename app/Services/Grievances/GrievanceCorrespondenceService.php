<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Actions\CodeRules\GenerateCodeAction;
use App\Contracts\SmsGateway;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceDispatchChannel;
use App\Enums\Grievance\GrievanceDispatchStatus;
use App\Enums\Grievance\GrievanceLetterLanguage;
use App\Enums\Grievance\GrievanceLetterStatus;
use App\Enums\Grievance\GrievanceLetterType;
use App\Enums\Grievance\GrievanceRecipientKind;
use App\Enums\Grievance\GrievanceRecipientType;
use App\Enums\Grievance\GrievanceSignatureMethod;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\GrievanceDecision;
use App\Models\GrievanceHearing;
use App\Models\GrievanceInformationRequest;
use App\Models\GrievanceLetter;
use App\Models\GrievanceLetterDispatch;
use App\Models\GrievanceLetterTemplate;
use App\Models\GrievanceReasonCode;
use App\Models\Organization;
use App\Models\OrganizationLetterhead;
use App\Models\OrganizationSeal;
use App\Models\Position;
use App\Models\User;
use App\Services\Calendar\EthiopianCalendarService;
use App\Services\SystemSettings\SystemSettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Official grievance correspondence (docs/grievance-management.md §8.8).
 *
 *   draft (from a template, tokens rendered) → finalized (outgoing reference
 *   number assigned) → signed → [sealed] → issued (PDF frozen, hashed) →
 *   dispatched; an issued letter is only ever VOIDED and re-issued.
 *
 * Templates use a fixed token whitelist replaced as plain text — nothing in
 * a template is executed. Signing records the signatory and method; a
 * signature image on a PDF is NOT presented as a digital signature, and a
 * qualified digital signature is refused because none is integrated.
 */
final class GrievanceCorrespondenceService
{
    public const DISK = 'local';

    /** Letter types the complainant may download once issued. */
    public const COMPLAINANT_TYPES = ['acknowledgment', 'hearing_notice', 'information_request', 'decision_letter', 'appeal_acknowledgment', 'escalation_notice', 'closure_letter'];

    /** Whitelisted template tokens. */
    public const TOKENS = [
        'case_number', 'employee_name', 'employee_number', 'organization_name', 'organization_unit', 'category_name',
        'subject', 'submission_date', 'letter_date', 'reference_number', 'recipient_name', 'recipient_organization',
        'handler_name', 'decision_date', 'decision_number', 'decision_summary', 'decision_text', 'appeal_notice',
        'approved_by_name', 'approved_by_position', 'hearing_date', 'hearing_time', 'hearing_location', 'hearing_mode',
        'information_request', 'information_due_date', 'appeal_deadline', 'closure_reason',
    ];

    public function __construct(
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceDecisionService $decisions,
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceNotifier $notifier,
        private readonly GenerateCodeAction $codes,
        private readonly EthiopianCalendarService $ethiopian,
        private readonly SystemSettingsService $settings,
        private readonly SmsGateway $sms,
    ) {}

    // ── Templates and tokens ────────────────────────────────────────────────

    public function resolveTemplate(GrievanceLetterType $type, GrievanceLetterLanguage $language, ?string $organizationId): ?GrievanceLetterTemplate
    {
        $day = now()->toDateString();

        return GrievanceLetterTemplate::query()
            ->where('template_type', $type->value)
            ->where('language', $language->value)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->orderByRaw('organization_id is null')
            ->first();
    }

    /**
     * Plain-text token replacement over a fixed whitelist. Unknown tokens are
     * left visible for the author to fix; no expression is ever evaluated.
     *
     * @param  array<string, string|null>  $values
     */
    public function renderTokens(string $template, array $values): string
    {
        $map = [];
        foreach (self::TOKENS as $token) {
            $map['{{'.$token.'}}'] = (string) ($values[$token] ?? '');
            $map['{{ '.$token.' }}'] = (string) ($values[$token] ?? '');
        }

        return strtr($template, $map);
    }

    /** @return array<string, string|null> */
    public function tokenValues(Grievance $grievance, GrievanceLetterLanguage $language, array $context = []): array
    {
        $am = $language === GrievanceLetterLanguage::Am;
        $grievance->loadMissing(['employee', 'organization', 'organizationUnit', 'category']);
        $name = fn ($model) => $model === null ? null : (($am ? $model->name_am : null) ?: $model->name_en);
        $date = fn (?CarbonInterface $d) => $this->formatDate($d, $language);
        $employee = $grievance->employee;
        $handler = $grievance->current_handler_type !== null ? $this->handlers->describe($grievance->current_handler_type, $grievance->current_handler_id) : null;

        $values = [
            'case_number' => $grievance->reference_number,
            'employee_name' => $employee ? ($am ? $employee->full_name : ($employee->name_en ?: $employee->full_name)) : null,
            'employee_number' => $employee?->employee_number,
            'organization_name' => $name($grievance->organization),
            'organization_unit' => $name($grievance->organizationUnit),
            'category_name' => $name($grievance->category),
            'subject' => $grievance->subject,
            'submission_date' => $date($grievance->submitted_at),
            'letter_date' => $date(now()),
            'recipient_name' => $employee ? ($am ? $employee->full_name : ($employee->name_en ?: $employee->full_name)) : null,
            'recipient_organization' => $name($grievance->organization),
            'handler_name' => $handler ? (($am ? $handler['name_am'] : null) ?: $handler['name_en']) : null,
            'appeal_deadline' => $date($grievance->appeal_deadline_at),
            'appeal_notice' => '',
            'reference_number' => '{{reference_number}}',
        ];

        $decision = $context['decision'] ?? null;
        if ($decision instanceof GrievanceDecision) {
            $approver = $decision->approved_by ? User::query()->find($decision->approved_by) : null;
            $values += [
                'decision_date' => $date($decision->approved_at ?? $decision->finalized_at ?? now()),
                'decision_number' => $decision->decision_no,
                'decision_summary' => $decision->decision_type ? (string) __('grievances.enums.decision_type.'.$decision->decision_type->value, [], $language === GrievanceLetterLanguage::Am ? 'am' : 'en') : null,
                'decision_text' => $decision->decision_text,
                'approved_by_name' => $approver?->employee instanceof Employee ? ($am ? $approver->employee->full_name : ($approver->employee->name_en ?: $approver->employee->full_name)) : $approver?->name,
                'approved_by_position' => $decision->approver_position_id ? $this->positionTitle(Position::query()->find($decision->approver_position_id), $am) : null,
            ];
            $values['appeal_notice'] = (string) __('grievances.letters.appeal_notice', [], $am ? 'am' : 'en');
        }
        $hearing = $context['hearing'] ?? null;
        if ($hearing instanceof GrievanceHearing) {
            $values += [
                'hearing_date' => $date($hearing->scheduled_at),
                'hearing_time' => $hearing->scheduled_at?->timezone(config('app.timezone'))->format('H:i'),
                'hearing_location' => $hearing->location,
                'hearing_mode' => (string) __('grievances.enums.hearing_mode.'.$hearing->mode->value, [], $am ? 'am' : 'en'),
            ];
        }
        $request = $context['information_request'] ?? null;
        if ($request instanceof GrievanceInformationRequest) {
            $values += ['information_request' => $request->request_text, 'information_due_date' => $date($request->due_at)];
        }
        if ($grievance->closure_reason_code) {
            $reason = GrievanceReasonCode::query()->where('type', 'closure')->where('code', $grievance->closure_reason_code)->first();
            $values['closure_reason'] = $reason ? (($am ? $reason->name_am : null) ?: $reason->name_en) : $grievance->closure_reason_code;
        }

        return $values;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    /**
     * Generate a draft from the configured template.
     *
     * @param  array{letter_type: string, language: string, decision_id?: string|null, hearing_id?: string|null, information_request_id?: string|null, appeal_id?: string|null}  $data
     */
    public function generate(Grievance $grievance, User $actor, array $data): GrievanceLetter
    {
        $this->access->authorize($this->access->canPrepareLetters($actor, $grievance) || $this->access->canLead($actor, $grievance, 'grievance_correspondence.create'));

        $type = GrievanceLetterType::from($data['letter_type']);
        $language = GrievanceLetterLanguage::from($data['language']);
        $organizationId = $this->issuingOrganization($grievance);
        $context = $this->context($grievance, $data);
        if ($type === GrievanceLetterType::DecisionLetter && ! ($context['decision'] ?? null) instanceof GrievanceDecision) {
            throw ValidationException::withMessages(['decision_id' => __('grievances.errors.decision_not_finalized')]);
        }

        if ($language === GrievanceLetterLanguage::Bilingual) {
            [$subject, $body] = $this->bilingual($type, $organizationId, $grievance, $context);
            $template = $this->resolveTemplate($type, GrievanceLetterLanguage::Am, $organizationId);
        } else {
            $template = $this->resolveTemplate($type, $language, $organizationId);
            if ($template === null) {
                throw ValidationException::withMessages(['letter_type' => __('grievances.errors.template_missing')]);
            }
            $values = $this->tokenValues($grievance, $language, $context);
            $subject = $this->renderTokens($template->subject_template, $values);
            $body = $this->renderTokens($template->body_template, $values);
        }

        return DB::transaction(function () use ($grievance, $actor, $type, $language, $organizationId, $context, $template, $subject, $body): GrievanceLetter {
            $letter = GrievanceLetter::query()->create([
                'grievance_id' => $grievance->getKey(),
                'case_stage_id' => $grievance->current_stage_id,
                'decision_id' => ($context['decision'] ?? null)?->getKey(),
                'hearing_id' => ($context['hearing'] ?? null)?->getKey(),
                'information_request_id' => ($context['information_request'] ?? null)?->getKey(),
                'appeal_id' => $context['appeal_id'] ?? null,
                'template_id' => $template?->getKey(),
                'organization_id' => $organizationId,
                'letter_type' => $type,
                'language' => $language,
                'subject' => mb_substr($subject, 0, 255),
                'body' => $body,
                'status' => GrievanceLetterStatus::Draft,
                'visible_to_complainant' => in_array($type->value, self::COMPLAINANT_TYPES, true),
                'created_by' => $actor->getKey(),
            ]);

            // Default TO: the complainant (for complainant-facing letters).
            if ($letter->visible_to_complainant && $grievance->employee) {
                $employee = $grievance->employee;
                $letter->recipients()->create([
                    'kind' => GrievanceRecipientKind::To,
                    'recipient_type' => GrievanceRecipientType::Employee,
                    'recipient_id' => $employee->getKey(),
                    'name' => $employee->full_name,
                    'organization_name' => $grievance->organization?->name_am ?: $grievance->organization?->name_en,
                    'sort_order' => 0,
                ]);
            }

            $this->audit->record(AuditEventType::GrievanceLetterGenerated, $actor, $letter, ['letter_type' => $type->value, 'language' => $language->value]);

            return $letter;
        });
    }

    /**
     * @param  array{subject?: string, body?: string, recipients?: list<array<string, mixed>>, attachments?: list<array<string, mixed>>, visible_to_complainant?: bool}  $data
     */
    public function updateDraft(GrievanceLetter $letter, User $actor, array $data): GrievanceLetter
    {
        $grievance = $letter->grievance;
        $this->access->authorize($grievance !== null && $this->access->canPrepareLetters($actor, $grievance));
        if ($letter->status !== GrievanceLetterStatus::Draft) {
            throw ValidationException::withMessages(['letter' => __('grievances.errors.letter_not_editable')]);
        }

        return DB::transaction(function () use ($letter, $data): GrievanceLetter {
            $letter = GrievanceLetter::query()->whereKey($letter->getKey())->lockForUpdate()->firstOrFail();
            if ($letter->status !== GrievanceLetterStatus::Draft) {
                throw ValidationException::withMessages(['letter' => __('grievances.errors.letter_not_editable')]);
            }
            $letter->fill(array_intersect_key($data, array_flip(['subject', 'body', 'visible_to_complainant'])))->save();

            if (array_key_exists('recipients', $data)) {
                $letter->recipients()->delete();
                foreach (array_values($data['recipients'] ?? []) as $i => $recipient) {
                    $letter->recipients()->create([
                        'kind' => GrievanceRecipientKind::from($recipient['kind']),
                        'recipient_type' => GrievanceRecipientType::from($recipient['recipient_type']),
                        'recipient_id' => $recipient['recipient_id'] ?? null,
                        'name' => $recipient['name'],
                        'position_title' => $recipient['position_title'] ?? null,
                        'organization_name' => $recipient['organization_name'] ?? null,
                        'address' => $recipient['address'] ?? null,
                        'email' => $recipient['email'] ?? null,
                        'sort_order' => $i,
                    ]);
                }
            }
            if (array_key_exists('attachments', $data)) {
                $letter->attachments()->delete();
                foreach (array_values($data['attachments'] ?? []) as $i => $attachment) {
                    $evidenceId = $attachment['evidence_id'] ?? null;
                    if ($evidenceId !== null && ! $letter->grievance->evidence()->whereKey($evidenceId)->exists()) {
                        throw ValidationException::withMessages(['attachments' => __('grievances.errors.stale')]);
                    }
                    $letter->attachments()->create([
                        'attachment_type' => $attachment['attachment_type'] ?? 'other',
                        'title' => $attachment['title'],
                        'evidence_id' => $evidenceId,
                        'reference_id' => $attachment['reference_id'] ?? null,
                        'sort_order' => $i,
                    ]);
                }
            }

            return $letter->refresh();
        });
    }

    /** Assign the outgoing reference number (once) and the letter date. */
    public function finalize(GrievanceLetter $letter, User $actor): GrievanceLetter
    {
        $grievance = $letter->grievance;
        $this->access->authorize($grievance !== null && ($this->access->canPrepareLetters($actor, $grievance) || $this->access->canLead($actor, $grievance, 'grievance_correspondence.create')));

        return DB::transaction(function () use ($letter, $actor): GrievanceLetter {
            $letter = GrievanceLetter::query()->whereKey($letter->getKey())->lockForUpdate()->firstOrFail();
            if ($letter->status !== GrievanceLetterStatus::Draft) {
                throw ValidationException::withMessages(['letter' => __('grievances.errors.stale')]);
            }
            if ($letter->recipients()->where('kind', GrievanceRecipientKind::To->value)->doesntExist()) {
                throw ValidationException::withMessages(['recipients' => __('grievances.errors.letter_needs_recipient')]);
            }

            $reference = $letter->reference_number ?? $this->codes->execute(CodeRuleEntityType::GrievanceLetter, ['organization_id' => $letter->organization_id], $actor, null, 'reference_number', $letter->getKey());
            $letter->forceFill([
                'status' => GrievanceLetterStatus::Finalized,
                'reference_number' => $reference,
                'letter_date' => now()->toDateString(),
                'body' => str_replace('{{reference_number}}', $reference, $letter->body),
                'subject' => str_replace('{{reference_number}}', $reference, $letter->subject),
            ])->save();
            $this->audit->record(AuditEventType::GrievanceLetterFinalized, $actor, $letter, ['reference_number' => $reference]);

            return $letter;
        });
    }

    /**
     * Sign as the authorized signatory. The signatory is the acting user's
     * own employee record and current position — never someone else's.
     */
    public function sign(GrievanceLetter $letter, User $actor, GrievanceSignatureMethod $method): GrievanceLetter
    {
        $grievance = $letter->grievance;
        $this->access->authorize($this->access->canSignLetter($actor, $letter));

        if ($method === GrievanceSignatureMethod::QualifiedDigitalSignature) {
            // No qualified/legal digital-signature infrastructure is integrated.
            throw ValidationException::withMessages(['method' => __('grievances.errors.qualified_signature_unavailable')]);
        }
        $employee = $this->access->employeeOf($actor);
        if ($employee === null) {
            throw ValidationException::withMessages(['method' => __('grievances.errors.no_employee_record')]);
        }
        if ($method === GrievanceSignatureMethod::SignatureImage && ! $this->signatureImage($employee)) {
            throw ValidationException::withMessages(['method' => __('grievances.errors.no_signature_image')]);
        }

        return DB::transaction(function () use ($letter, $actor, $method, $employee): GrievanceLetter {
            $letter = GrievanceLetter::query()->whereKey($letter->getKey())->lockForUpdate()->firstOrFail();
            if ($letter->status !== GrievanceLetterStatus::Finalized) {
                throw ValidationException::withMessages(['letter' => __('grievances.errors.stale')]);
            }
            $this->access->authorize($this->access->canSignLetter($actor, $letter));
            $letter->forceFill([
                'status' => GrievanceLetterStatus::Signed,
                'signatory_user_id' => $actor->getKey(),
                'signatory_employee_id' => $employee->getKey(),
                'signatory_position_id' => $this->handlers->placementOf($employee)['position_id'],
                'signature_method' => $method,
                'signed_at' => now(),
            ])->save();
            $this->audit->record(AuditEventType::GrievanceLetterSigned, $actor, $letter, ['reference_number' => $letter->reference_number, 'method' => $method->value]);

            return $letter;
        });
    }

    /** Apply the issuing organization's controlled seal (permission + audit). */
    public function applySeal(GrievanceLetter $letter, User $actor, OrganizationSeal $seal): GrievanceLetter
    {
        $this->access->authorize($actor->can('grievance_correspondence.apply_seal') && ($this->access->isRegistryOfficer($actor, $letter) || $this->access->canViewDetails($actor, $letter->grievance)));
        if ($seal->organization_id !== $letter->organization_id || $seal->status !== 'active' || $seal->approved_at === null) {
            throw ValidationException::withMessages(['seal_id' => __('grievances.errors.seal_not_usable')]);
        }

        return DB::transaction(function () use ($letter, $actor, $seal): GrievanceLetter {
            $letter = GrievanceLetter::query()->whereKey($letter->getKey())->lockForUpdate()->firstOrFail();
            if ($letter->status !== GrievanceLetterStatus::Signed) {
                throw ValidationException::withMessages(['letter' => __('grievances.errors.stale')]);
            }
            $letter->forceFill(['seal_id' => $seal->getKey(), 'seal_applied_by' => $actor->getKey(), 'seal_applied_at' => now()])->save();
            $this->audit->record(AuditEventType::GrievanceLetterSealed, $actor, $letter, ['reference_number' => $letter->reference_number, 'seal_id' => $seal->getKey()]);

            return $letter;
        });
    }

    /**
     * Freeze the letter: render the PDF, store it privately with its SHA-256,
     * mark ISSUED (immutable from here), issue the decision when this is the
     * decision letter, and dispatch in-app to the complainant.
     */
    public function issue(GrievanceLetter $letter, User $actor): GrievanceLetter
    {
        $grievance = $letter->grievance;
        $this->access->authorize($grievance !== null && $actor->can('grievance_correspondence.issue')
            && ($this->access->isRegistryOfficer($actor, $letter) || $this->access->canLead($actor, $grievance, 'grievance_correspondence.issue') || $this->access->canHandle($actor, $grievance, 'grievance_correspondence.issue')));

        $issued = DB::transaction(function () use ($letter, $actor): GrievanceLetter {
            $grievance = Grievance::query()->whereKey($letter->grievance_id)->lockForUpdate()->firstOrFail();
            $letter = GrievanceLetter::query()->whereKey($letter->getKey())->lockForUpdate()->firstOrFail();
            if ($letter->status !== GrievanceLetterStatus::Signed) {
                throw ValidationException::withMessages(['letter' => __('grievances.errors.letter_not_signed')]);
            }

            if ($letter->letter_type === GrievanceLetterType::DecisionLetter && $letter->decision_id !== null) {
                $decision = GrievanceDecision::query()->whereKey($letter->decision_id)->lockForUpdate()->firstOrFail();
                $isCorrection = $letter->supersedes_letter_id !== null
                    && $letter->supersedes?->status === GrievanceLetterStatus::Voided
                    && $letter->supersedes->decision_id === $decision->getKey()
                    && $decision->status === GrievanceDecisionStatus::Issued;
                // Correcting the issued artifact must not reopen the decision or reset its appeal window.
                if (! $isCorrection) {
                    $this->decisions->markIssued($decision, $actor);
                }
            }

            $pdf = $this->renderPdf($letter);
            $path = 'grievances/'.$letter->grievance_id.'/letters/'.preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $letter->reference_number).'-'.$letter->getKey().'.pdf';
            if (Storage::disk(self::DISK)->exists($path)) {
                // Never overwrite an issued artifact.
                throw ValidationException::withMessages(['letter' => __('grievances.errors.stale')]);
            }
            Storage::disk(self::DISK)->put($path, $pdf);

            $letter->forceFill([
                'status' => GrievanceLetterStatus::Issued,
                'pdf_disk' => self::DISK,
                'pdf_path' => $path,
                'pdf_sha256' => hash('sha256', $pdf),
                'generated_at' => now(),
                'issued_by' => $actor->getKey(),
                'issued_at' => now(),
            ])->save();

            $this->audit->record(AuditEventType::GrievanceLetterIssued, $actor, $letter, ['reference_number' => $letter->reference_number, 'sha256' => $letter->pdf_sha256]);
            if ($letter->visible_to_complainant) {
                $this->timeline->record($letter->grievance, 'letter_issued', $actor, ['letter_type' => $letter->letter_type->value, 'reference_number' => $letter->reference_number]);
                $this->recordDispatch($letter, GrievanceDispatchChannel::InApp, null, $actor, GrievanceDispatchStatus::Sent);
                $this->notifier->toComplainant($letter->grievance, 'letter_issued');
            }

            return $letter;
        });

        return $issued;
    }

    /** Void an issued letter (reason required); the artifact is kept. */
    public function void(GrievanceLetter $letter, User $actor, string $reason): GrievanceLetter
    {
        $grievance = $letter->grievance;
        $this->access->authorize($grievance !== null && $actor->can('grievance_correspondence.issue')
            && ($this->access->canLead($actor, $grievance, 'grievance_correspondence.issue') || $this->access->isRegistryOfficer($actor, $letter)));
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('grievances.errors.reason_required')]);
        }

        return DB::transaction(function () use ($letter, $actor, $reason): GrievanceLetter {
            $letter = GrievanceLetter::query()->whereKey($letter->getKey())->lockForUpdate()->firstOrFail();
            if ($letter->status !== GrievanceLetterStatus::Issued) {
                throw ValidationException::withMessages(['letter' => __('grievances.errors.stale')]);
            }
            $letter->forceFill(['status' => GrievanceLetterStatus::Voided, 'voided_by' => $actor->getKey(), 'voided_at' => now(), 'void_reason' => $reason])->save();
            $this->audit->record(AuditEventType::GrievanceLetterVoided, $actor, $letter, ['reference_number' => $letter->reference_number], null, $reason);

            return $letter;
        });
    }

    /** A new draft version of a voided letter (same content to correct). */
    public function revise(GrievanceLetter $voided, User $actor): GrievanceLetter
    {
        $grievance = $voided->grievance;
        $this->access->authorize($grievance !== null && $this->access->canPrepareLetters($actor, $grievance));
        if ($voided->status !== GrievanceLetterStatus::Voided) {
            throw ValidationException::withMessages(['letter' => __('grievances.errors.stale')]);
        }

        return DB::transaction(function () use ($voided, $actor): GrievanceLetter {
            $new = $voided->replicate([
                'reference_number', 'status', 'letter_date', 'signatory_employee_id', 'signatory_position_id', 'signatory_user_id',
                'signature_method', 'signed_at', 'seal_id', 'seal_applied_by', 'seal_applied_at', 'pdf_disk', 'pdf_path',
                'pdf_sha256', 'generated_at', 'issued_by', 'issued_at', 'voided_by', 'voided_at', 'void_reason', 'legacy_decision_letter_id',
            ]);
            $new->forceFill(['status' => GrievanceLetterStatus::Draft, 'supersedes_letter_id' => $voided->getKey(), 'created_by' => $actor->getKey()])->save();
            foreach ($voided->recipients as $recipient) {
                $new->recipients()->create($recipient->only(['kind', 'recipient_type', 'recipient_id', 'name', 'position_title', 'organization_name', 'address', 'email', 'sort_order']));
            }
            foreach ($voided->attachments as $attachment) {
                $new->attachments()->create($attachment->only(['attachment_type', 'title', 'evidence_id', 'reference_id', 'sort_order']));
            }
            $this->audit->record(AuditEventType::GrievanceLetterGenerated, $actor, $new, ['supersedes' => $voided->getKey()]);

            return $new;
        });
    }

    /**
     * Record a dispatch. Email and SMS carry a notice only (never the
     * decision); "delivered" is recorded only when confirmed.
     */
    public function dispatch(GrievanceLetter $letter, User $actor, GrievanceDispatchChannel $channel, ?string $recipientId, ?string $notes): GrievanceLetterDispatch
    {
        $grievance = $letter->grievance;
        $this->access->authorize($grievance !== null && $actor->can('grievance_correspondence.issue')
            && ($this->access->isRegistryOfficer($actor, $letter) || $this->access->canLead($actor, $grievance, 'grievance_correspondence.issue') || $this->access->canHandle($actor, $grievance, 'grievance_correspondence.issue')));
        if ($letter->status !== GrievanceLetterStatus::Issued) {
            throw ValidationException::withMessages(['letter' => __('grievances.errors.letter_not_issued')]);
        }

        $status = GrievanceDispatchStatus::Sent;
        $failure = null;
        $destination = null;
        if ($channel === GrievanceDispatchChannel::SmsNotice) {
            $phone = $grievance->employee?->phone;
            $destination = $phone ? substr($phone, 0, 3).'****'.substr($phone, -2) : null;
            $ok = $phone && $this->sms->isConfigured() && $this->sms->send($phone, (string) __('grievances.notifications.sms_letter', ['case' => $grievance->reference_number]));
            if (! $ok) {
                $status = GrievanceDispatchStatus::Failed;
                $failure = $phone ? 'sms_gateway_failed' : 'no_phone';
            }
        } elseif ($channel === GrievanceDispatchChannel::Email) {
            $this->notifier->toComplainant($grievance, 'letter_issued');
        }

        return $this->recordDispatch($letter, $channel, $recipientId, $actor, $status, $destination, $failure, $notes);
    }

    public function acknowledge(GrievanceLetterDispatch $dispatch, User $actor): GrievanceLetterDispatch
    {
        $letter = $dispatch->letter;
        $grievance = $letter?->grievance;
        $this->access->authorize($grievance !== null && ($this->access->isComplainant($actor, $grievance) || $this->access->isRegistryOfficer($actor, $letter) || $this->access->canHandle($actor, $grievance, 'grievance_correspondence.issue')));
        $dispatch->forceFill(['status' => GrievanceDispatchStatus::Acknowledged, 'acknowledged_at' => now(), 'acknowledged_by' => $actor->getKey()])->save();

        return $dispatch;
    }

    /** Authorized, integrity-checked, audited download of an issued (or legacy) PDF. */
    public function download(GrievanceLetter $letter, User $actor): StreamedResponse
    {
        $this->access->authorize($this->access->canDownloadDocument($actor, $letter));
        abort_if($letter->pdf_path === null, 404);

        $disk = Storage::disk($letter->pdf_disk ?? self::DISK);
        abort_unless($disk->exists($letter->pdf_path), 404);
        if ($letter->pdf_sha256 !== null && hash_file('sha256', $disk->path($letter->pdf_path)) !== $letter->pdf_sha256) {
            report(new \RuntimeException('Grievance letter integrity check failed: '.$letter->getKey()));
            abort(409, __('grievances.errors.integrity_failed'));
        }

        $this->audit->record(AuditEventType::GrievanceLetterDownloaded, $actor, $letter, ['reference_number' => $letter->reference_number]);

        return $disk->download($letter->pdf_path, preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($letter->reference_number ?? 'letter')).'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Draft preview (not stored, watermarked as a draft). */
    public function preview(GrievanceLetter $letter, User $actor): string
    {
        $this->access->authorize($letter->grievance !== null && ($this->access->canPrepareLetters($actor, $letter->grievance) || $this->access->canDownloadDocument($actor, $letter)));

        return $this->renderPdf($letter, preview: true);
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function renderPdf(GrievanceLetter $letter, bool $preview = false): string
    {
        $letter->loadMissing(['recipients', 'attachments', 'organization', 'signatoryEmployee', 'signatoryPosition', 'seal', 'grievance']);
        $organization = $letter->organization;
        $letterhead = $organization ? OrganizationLetterhead::query()->where('organization_id', $organization->getKey())->first() : null;
        $am = $letter->language !== GrievanceLetterLanguage::En;

        $pdf = Pdf::loadView('grievances.letter', [
            'letter' => $letter,
            'am' => $am,
            'preview' => $preview,
            'organization' => $organization,
            'letterhead' => $letterhead,
            'headerLine' => ($am ? $letterhead?->header_line_am : $letterhead?->header_line_en) ?: $this->settings->get('general', 'organization_name', config('app.name')),
            'logo' => $this->logoDataUri($organization),
            'seal' => $letter->seal ? $this->privateImageDataUri($letter->seal->disk, $letter->seal->path, $letter->seal->mime_type) : null,
            'signature' => $letter->signature_method === GrievanceSignatureMethod::SignatureImage && $letter->signatoryEmployee ? $this->signatureImage($letter->signatoryEmployee) : null,
            'dateGregorian' => $letter->letter_date?->format('d/m/Y'),
            'dateEthiopian' => $letter->letter_date ? $this->ethiopian->formatGregorianDateAsEthiopian(Carbon::parse($letter->letter_date), 'am') : null,
            'signatoryName' => $letter->signatoryEmployee ? ($am ? $letter->signatoryEmployee->full_name : ($letter->signatoryEmployee->name_en ?: $letter->signatoryEmployee->full_name)) : null,
            'signatoryPosition' => $this->positionTitle($letter->signatoryPosition, $am),
        ])->setPaper('a4');

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans') ?? $dompdf->getFontMetrics()->getFont('Helvetica');
        $canvas->page_text($canvas->get_width() - 80, $canvas->get_height() - 28, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.4, 0.4, 0.4]);

        return (string) $dompdf->output();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The letter is issued by the handling body's organization (else the complainant's). */
    private function issuingOrganization(Grievance $grievance): ?string
    {
        $stage = $grievance->currentStage;

        return $stage?->organization_id ?? $grievance->organization_id;
    }

    /** @return array<string, mixed> */
    private function context(Grievance $grievance, array $data): array
    {
        $context = [];
        if (! empty($data['decision_id'])) {
            $decision = $grievance->decisions()->whereKey($data['decision_id'])->first();
            if ($decision === null || ! in_array($decision->status->value, ['finalized', 'issued'], true)) {
                throw ValidationException::withMessages(['decision_id' => __('grievances.errors.decision_not_finalized')]);
            }
            $context['decision'] = $decision;
        }
        if (! empty($data['hearing_id'])) {
            $context['hearing'] = $grievance->hearings()->whereKey($data['hearing_id'])->firstOrFail();
        }
        if (! empty($data['information_request_id'])) {
            $context['information_request'] = $grievance->informationRequests()->whereKey($data['information_request_id'])->firstOrFail();
        }
        if (! empty($data['appeal_id'])) {
            $context['appeal_id'] = $grievance->appeals()->whereKey($data['appeal_id'])->firstOrFail()->getKey();
        }

        return $context;
    }

    /** @return array{0: string, 1: string} */
    private function bilingual(GrievanceLetterType $type, ?string $organizationId, Grievance $grievance, array $context): array
    {
        $am = $this->resolveTemplate($type, GrievanceLetterLanguage::Am, $organizationId);
        $en = $this->resolveTemplate($type, GrievanceLetterLanguage::En, $organizationId);
        if ($am === null || $en === null) {
            throw ValidationException::withMessages(['language' => __('grievances.errors.template_missing')]);
        }
        $amValues = $this->tokenValues($grievance, GrievanceLetterLanguage::Am, $context);
        $enValues = $this->tokenValues($grievance, GrievanceLetterLanguage::En, $context);

        return [
            $this->renderTokens($am->subject_template, $amValues).' / '.$this->renderTokens($en->subject_template, $enValues),
            $this->renderTokens($am->body_template, $amValues)."\n\n────────\n\n".$this->renderTokens($en->body_template, $enValues),
        ];
    }

    private function formatDate(?CarbonInterface $date, GrievanceLetterLanguage $language): ?string
    {
        if ($date === null) {
            return null;
        }
        $carbon = Carbon::parse($date)->timezone(config('app.timezone'));

        return $language === GrievanceLetterLanguage::En
            ? $carbon->format('d F Y')
            : $this->ethiopian->formatGregorianDateAsEthiopian($carbon, 'am');
    }

    private function positionTitle(?Position $position, bool $am): ?string
    {
        return $position === null ? null : (($am ? $position->title_am : null) ?: $position->title_en);
    }

    private function logoDataUri(?Organization $organization): ?string
    {
        if ($organization?->logo_path === null) {
            return null;
        }

        return $this->privateImageDataUri('public', $organization->logo_path, null);
    }

    private function signatureImage(Employee $employee): ?string
    {
        return $employee->signature_path ? $this->privateImageDataUri('local', $employee->signature_path, null) : null;
    }

    private function privateImageDataUri(string $disk, string $path, ?string $mime): ?string
    {
        if (str_contains($path, '..') || ! Storage::disk($disk)->exists($path)) {
            return null;
        }
        $contents = (string) Storage::disk($disk)->get($path);
        $mime ??= (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'image/png';
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    private function recordDispatch(GrievanceLetter $letter, GrievanceDispatchChannel $channel, ?string $recipientId, User $actor, GrievanceDispatchStatus $status, ?string $destination = null, ?string $failure = null, ?string $notes = null): GrievanceLetterDispatch
    {
        $dispatch = GrievanceLetterDispatch::query()->create([
            'letter_id' => $letter->getKey(),
            'recipient_id' => $recipientId,
            'channel' => $channel,
            'status' => $status,
            'destination' => $destination,
            'provider_reference' => $notes,
            'queued_at' => now(),
            'sent_at' => $status === GrievanceDispatchStatus::Sent ? now() : null,
            'failed_at' => $status === GrievanceDispatchStatus::Failed ? now() : null,
            'failure_reason' => $failure,
            'dispatched_by' => $actor->getKey(),
        ]);
        $this->audit->record(AuditEventType::GrievanceLetterDispatched, $actor, $letter, ['channel' => $channel->value, 'status' => $status->value]);

        return $dispatch;
    }
}
