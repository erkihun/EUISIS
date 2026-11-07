<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Enums\SubmitterIdentityMode;

/**
 * What one public submission type asks of the submitter. Built only by
 * PublicSubmissionPolicy; read by the Form Requests (validation), the
 * submission service (what is stored) and, through toPublicArray(), by the
 * public page (which fields to show and mark required). The server stays
 * authoritative: the page only mirrors this.
 */
final readonly class SubmissionRequirements
{
    public function __construct(
        public ServiceFeedbackType $type,
        public bool $nameRequired,
        public bool $phoneRequired,
        public bool $anonymousAllowed,
        public bool $tipTypeRequired,
        /** An employee-targeted submission must name the service received. */
        public bool $serviceRequired,
        public bool $narrativeRequired,
        public bool $evidenceAllowed,
        public bool $incidentContext,
        public bool $involvedParties,
        public bool $submitterAddress,
        public bool $submitterOrganization,
    ) {}

    /** The identity policy recorded on the row, so a later policy change cannot reinterpret it. */
    public function identityMode(): SubmitterIdentityMode
    {
        return $this->anonymousAllowed ? SubmitterIdentityMode::Optional : SubmitterIdentityMode::Identified;
    }

    /** @return array<string, bool|string> */
    public function toPublicArray(): array
    {
        return [
            'type' => $this->type->value,
            'name_required' => $this->nameRequired,
            'phone_required' => $this->phoneRequired,
            'anonymous_allowed' => $this->anonymousAllowed,
            'tip_type_required' => $this->tipTypeRequired,
            'service_required' => $this->serviceRequired,
            'narrative_required' => $this->narrativeRequired,
            'evidence_allowed' => $this->evidenceAllowed,
            'incident_context' => $this->incidentContext,
            'involved_parties' => $this->involvedParties,
            'submitter_address' => $this->submitterAddress,
            'submitter_organization' => $this->submitterOrganization,
        ];
    }
}
