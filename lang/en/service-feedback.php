<?php

declare(strict_types=1);

/*
 * Public Client Service Feedback — messages the anonymous visitor reads.
 * Mirrored in lang/am/service-feedback.php.
 */
return [
    'service_not_provided' => 'This service is not provided by this officer.',

    // Organization feedback QR management
    'qrGenerated' => 'The organization feedback QR is enabled.',
    'qrDisabled' => 'The organization feedback QR is disabled; scans show the unavailable page.',
    'qrEnabled' => 'The organization feedback QR is enabled again.',
    'qrRotated' => 'A new organization feedback QR was issued; the previous code is revoked.',
    'qrRevoked' => 'The organization feedback QR is revoked.',

    'validation' => [
        'service_required' => 'Please choose the service you received.',
        'rating_required' => 'Please choose a satisfaction rating.',
        'rating_range' => 'Please choose a rating between 1 and 5 stars.',
        'message_required' => 'Please describe your suggestion or complaint.',
        'attachments_max' => 'You can attach up to 3 files.',
        'attachment_type' => 'Only PDF, JPEG and PNG files are accepted.',
        'attachment_size' => 'Each file must be 5 MB or smaller.',
        'incident_date_future' => 'The incident date cannot be in the future.',
    ],
];
