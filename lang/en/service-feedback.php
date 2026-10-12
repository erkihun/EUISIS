<?php

declare(strict_types=1);

/*
 * Public Client Service Feedback — messages the anonymous visitor reads.
 * Mirrored in lang/am/service-feedback.php.
 */
return [
    'service_not_provided' => 'This service is not provided by this officer.',
    'form_replayed' => 'This form was already submitted. Open the page again to send another response.',
    'category_not_applicable' => 'This category does not apply to this submission.',

    // Category management
    'category_saved' => 'The category was saved.',
    'category_retired' => 'Submissions refer to this category, so it was retired instead of deleted.',
    'category_deleted' => 'The category was deleted.',
    'category_code_taken' => 'A category with this code already exists in this scope.',

    // Organization feedback QR management
    'qrGenerated' => 'The organization feedback QR is enabled.',
    'qrDisabled' => 'The organization feedback QR is disabled; scans show the unavailable page.',
    'qrEnabled' => 'The organization feedback QR is enabled again.',
    'qrRotated' => 'A new organization feedback QR was issued; the previous code is revoked.',
    'qrRevoked' => 'The organization feedback QR is revoked.',

    // Workflow, assignment and routing
    'status_changed' => 'The status was updated.',
    'transition_not_allowed' => 'This status cannot follow the current one for this submission type.',
    'assigned' => 'The submission was handed to the handler.',
    'handler_invalid' => 'Choose an existing handler.',
    'handler_out_of_scope' => 'The handler must belong to the submission\'s institution.',
    'handler_is_target' => 'The employee a submission is about cannot handle it.',
    'not_assignable' => 'A submission with a recorded outcome can no longer be assigned.',
    'rule_saved' => 'The routing rule was saved.',
    'rule_retired' => 'Submissions were routed by this rule, so it was retired instead of deleted.',
    'rule_deleted' => 'The routing rule was deleted.',
    'category_out_of_scope' => 'The category must be city-wide or belong to the rule\'s institution.',
    'settings_saved' => 'The public feedback policy was saved.',

    'types' => [
        'feedback' => 'feedback',
        'suggestion' => 'suggestion',
        'complaint' => 'complaint',
    ],

    'notifications' => [
        'unrouted_title' => 'Public submission needs a handler',
        'unrouted' => 'Public :type :reference matched no routing rule and is waiting for a handler.',
    ],

    'validation' => [
        'service_required' => 'Please choose the service you received.',
        'rating_required' => 'Please choose a satisfaction rating.',
        'rating_range' => 'Please choose a rating between 1 and 5 stars.',
        'message_required' => 'Please describe your suggestion or complaint.',
        'attachments_max' => 'You can attach up to 3 files.',
        'attachment_type' => 'Only PDF, JPEG and PNG files are accepted.',
        'attachment_size' => 'Each file must be 5 MB or smaller.',
        'incident_date_future' => 'The incident date cannot be in the future.',
        'name_required' => 'Please enter your name.',
        'contact_required' => 'Please enter a phone number or an e-mail address so we can reach you.',
        'contact_invalid' => 'Please enter a valid phone number or e-mail address.',
        'attachments_disabled' => 'Files cannot be attached at the moment.',
    ],
];
