<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts\Extensions;

use App\Enums\CodeRuleEntityType;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;

/**
 * One public submission type, as its own module defines it (ADR 0032):
 * Service feedback, Tip-offs and Public complaints each implement this and
 * register it with PublicSubmissionTypes from their provider. An extension
 * point: implemented by the type modules, never bound in the container. Feedback (the
 * shared intake and case handling) asks the type what the public form needs
 * and where its records live; it never names a type module.
 */
interface PublicSubmissionType
{
    public function type(): ServiceFeedbackType;

    /** The table the type's module owns; the intake writes the submission there. */
    public function table(): string;

    /**
     * What the type asks of the submitter, as the named flags of the intake's
     * requirements (nameRequired, phoneRequired, anonymousAllowed,
     * tipTypeRequired, serviceRequired, narrativeRequired, evidenceAllowed,
     * incidentContext, involvedParties, submitterAddress, submitterOrganization).
     *
     * @return array<string, bool>
     */
    public function requirements(bool $attachmentsEnabled): array;

    /**
     * Validation rules of the type's own form fields, on top of the intake's.
     * A key here replaces the intake's rule of the same name.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array;

    /** @return array<string, string> attribute names for the type's validation messages */
    public function attributes(): array;

    /**
     * The type's own columns to store from the validated form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function storedAttributes(array $data): array;

    /** The Code Rule that issues the type's references. */
    public function codeRule(): CodeRuleEntityType;

    /**
     * How the public may track a submission by its reference: 'reference',
     * 'reference_and_phone', or null when this type is not tracked.
     */
    public function tracking(): ?string;
}
