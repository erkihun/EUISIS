<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The fields PublicSubmissionPolicy requires of a public form, added to a
 * test payload only where the test did not set them itself. Tests written
 * before the identity matrix posted anonymous Feedback/Complaints and untyped
 * suggestions; this lets them keep testing what they were about.
 *
 * Tests that are ABOUT a missing name, phone, comment or tip type must post
 * their payload without this helper.
 */
final class PublicSubmissionPayload
{
    /**
     * @param  'feedback'|'suggestion'|'complaint'  $type
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function complete(string $type, array $payload): array
    {
        $required = match ($type) {
            'feedback' => ['client_name' => 'Test Visitor', 'client_contact' => '+251911000000', 'comment' => 'Helpful service.'],
            // The complaint form (PublicComplaintForm): address, identity document, remedy and the declaration.
            'complaint' => ['client_name' => 'Test Visitor', 'client_contact' => '+251911000000', ...self::complaintForm()],
            'suggestion' => ['tip_type' => 'IMPROPER_PRACTICE'],
        };

        return $payload + $required;
    }

    /**
     * The complaint form's required parts (PublicComplaintForm): address,
     * sex, identity document, the remedy sought and the declaration.
     *
     * @return array<string, mixed>
     */
    public static function complaintForm(): array
    {
        return [
            'submitter_address' => ['sub_city' => 'Bole', 'woreda' => '03'],
            'complaint_form' => ['gender' => 'female', 'id_type' => 'residence_id', 'id_number' => 'AA-123456', 'remedy_sought' => 'Serve the request.', 'declaration' => '1'],
        ];
    }
}
