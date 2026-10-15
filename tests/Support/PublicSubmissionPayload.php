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
            'complaint' => ['client_name' => 'Test Visitor', 'client_contact' => '+251911000000'],
            'suggestion' => ['tip_type' => 'IMPROPER_PRACTICE'],
        };

        return $payload + $required;
    }
}
