<?php

declare(strict_types=1);

namespace Modules\PublicComplaints\Application\Services;

use App\Enums\CodeRuleEntityType;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Contracts\Extensions\PublicSubmissionType;

/**
 * The public complaint, as the public intake takes it (ADR 0032): answered to
 * a named person, so never anonymous; the full complaint form
 * (PublicComplaintForm) on top of the name, phone and the complaint itself.
 */
final class ComplaintSubmissionType implements PublicSubmissionType
{
    public function type(): ServiceFeedbackType
    {
        return ServiceFeedbackType::Complaint;
    }

    public function table(): string
    {
        return 'public_complaints';
    }

    public function requirements(bool $attachmentsEnabled): array
    {
        return [
            'nameRequired' => true,
            'phoneRequired' => true,
            'anonymousAllowed' => false,
            'tipTypeRequired' => false,
            'serviceRequired' => false,
            'narrativeRequired' => true,
            'evidenceAllowed' => $attachmentsEnabled,
            'incidentContext' => true,
            'involvedParties' => false,
            // The complaint form asks where the complainant lives (sub-city, woreda, house number).
            'submitterAddress' => true,
            'submitterOrganization' => true,
        ];
    }

    public function rules(): array
    {
        return PublicComplaintForm::rules();
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach (['gender', 'id_type', 'id_number', 'alt_phone', 'principal_name', 'poa_reference', 'poa_date', 'remedy_sought', 'declaration', 'prior_woreda_date', 'prior_sub_city_date', 'other_pages'] as $field) {
            $attributes["complaint_form.{$field}"] = __("service-feedback.complaint_form.{$field}");
        }

        return $attributes + [
            'submitter_address.sub_city' => __('service-feedback.complaint_form.sub_city'),
            'submitter_address.woreda' => __('service-feedback.complaint_form.woreda'),
        ];
    }

    public function storedAttributes(array $data): array
    {
        return ['complaint_details' => PublicComplaintForm::details(is_array($data['complaint_form'] ?? null) ? $data['complaint_form'] : [])];
    }

    public function codeRule(): CodeRuleEntityType
    {
        return CodeRuleEntityType::PublicServiceComplaint;
    }

    public function tracking(): ?string
    {
        // A complaint names its complainant: the phone they gave proves it is theirs.
        return 'reference_and_phone';
    }
}
