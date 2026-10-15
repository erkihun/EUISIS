<?php

declare(strict_types=1);

/*
 * Public Client Service Feedback — messages the anonymous visitor reads.
 * Mirrored in lang/am/service-feedback.php.
 */
return [
    'service_not_provided' => 'This service is not provided by this officer.',
    'form_replayed' => 'This form was already submitted. Open the page again to send another response.',
    'submission_failed' => 'Your submission could not be saved and nothing was stored. Please try again in a moment.',
    'too_many_submissions' => 'Too many messages were sent from this device. Please wait a few minutes and try again; what you typed is still on the page.',
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
    'export_queued' => 'Export queued. It appears in the list below once the file is ready.',
    'subject_added' => 'The employee was named as an investigation subject.',
    'subject_removed' => 'The investigation subject was removed.',
    'subject_not_in_organization' => 'Choose an active employee of this submission\'s institution.',
    'subject_is_target' => 'The employee the complaint is about is its target, not a subject.',
    'subject_duplicate' => 'This employee is already named.',
    'referral_requested' => 'The discipline referral was requested.',
    'referral_decided' => 'The referral decision was recorded.',
    'referral_not_reviewed' => 'A referral can be requested once the complaint has been reviewed.',
    'referral_open_exists' => 'A referral request is already waiting for a decision.',
    'referral_already_decided' => 'This referral request was already decided.',
    'referral_already_accepted' => 'An accepted referral already stands for this complaint.',

    'export_columns' => [
        'submitted_at' => 'Submitted At',
        'reference' => 'Reference',
        'type' => 'Type',
        'target_type' => 'Target Type',
        'target_name' => 'Target',
        'organization' => 'Organization',
        'organization_unit' => 'Unit',
        'category' => 'Category',
        'status' => 'Status',
        'handler' => 'Handler',
        'client_name' => 'Submitter Name',
        'client_contact' => 'Submitter Phone / Contact',
        'client_email' => 'Submitter E-mail',
        'submitter_organization' => 'Submitter Organization',
        'submitter_address' => 'Submitter Address',
        'tip_type' => 'Tip-off Type',
        'involved_parties' => 'Other Persons or Offices Involved',
        'intake_channel' => 'Channel',
        'subject' => 'Subject',
        'message' => 'Message',
    ],

    'types' => [
        'feedback' => 'feedback',
        'suggestion' => 'tip-off',
        'complaint' => 'complaint',
    ],

    'tip_types' => [
        'CORRUPTION' => 'Corruption Tip-off',
        'IMPROPER_PRACTICE' => 'Improper Practice Tip-off',
        'UNCLASSIFIED_LEGACY' => 'Unclassified (before Tip-off types)',
    ],

    'evidence_kind' => [
        'photo' => 'Photo',
        'video' => 'Video',
        'audio' => 'Audio',
        'document' => 'Document',
    ],

    'notifications' => [
        'open' => 'Open submission',
        'unrouted_title' => 'Public submission needs a handler',
        'unrouted' => 'Public :type :reference matched no routing rule and is waiting for a handler.',
        'assigned_title' => 'Public submission assigned to you',
        'assigned' => 'Public :type :reference was handed to you or your team.',
        'resolved_title' => 'Public submission outcome recorded',
        'resolved' => 'An outcome was recorded for public :type :reference.',
        'referred_title' => 'Discipline referral requested',
        'referred' => 'A discipline referral was requested for public :type :reference and waits for a decision.',
    ],

    'acknowledgement' => [
        'subject' => 'Your :type was received',
        'greeting' => 'Thank you.',
        'received' => 'Your :type was received on :time.',
        'reference' => 'Reference number: :reference. Please keep it for any follow-up.',
        'no_tracking' => 'This message confirms receipt only; the institution will act on it internally.',
    ],

    'validation' => [
        'service_required' => 'Please choose the service you received.',
        'rating_required' => 'Please choose a satisfaction rating.',
        'rating_range' => 'Please choose a rating between 1 and 5 stars.',
        'message_required' => 'Please describe what happened.',
        'comment_required' => 'Please tell us about the service you received.',
        'tip_type_required' => 'Please choose the type of Tip-off.',
        'tip_type_invalid' => 'Please choose one of the two Tip-off types.',
        'attachments_max' => 'You can attach up to :max files.',
        'attachment_type' => 'Only photos, videos, audio recordings and PDF files are accepted.',
        'attachment_size' => 'This file is too large.',
        'attachment_size_kind' => ':kind files must be :max MB or smaller.',
        'attachments_total' => 'All files together must be :max MB or smaller.',
        'incident_date_future' => 'The incident date cannot be in the future.',
        'name_required' => 'Please enter your name.',
        'contact_required' => 'Please enter your phone number.',
        'contact_invalid' => 'Please enter a valid phone number or e-mail address.',
        'phone_invalid' => 'Please enter a valid phone number.',
        'email_invalid' => 'Please enter a valid e-mail address.',
        'attachments_disabled' => 'Files cannot be attached at the moment.',
    ],
];
