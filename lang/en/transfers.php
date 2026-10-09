<?php

declare(strict_types=1);

return [
    'title' => 'Employee Transfers',
    'new_transfer' => 'New Transfer',
    'create_transfer' => 'Create Transfer',
    'transfer_details' => 'Transfer Details',
    'edit_transfer' => 'Edit Transfer',
    'from_organization' => 'From Organization',
    'to_organization' => 'To Organization',
    'from_position' => 'From Position',
    'to_position' => 'To Position',
    'effective_date' => 'Effective Date',
    'transfer_reason' => 'Transfer Reason',
    'rejection_reason' => 'Rejection Reason',

    // Announcement flash / domain messages
    'announcementCreated' => 'Transfer announcement created.',
    'announcementUpdated' => 'Transfer announcement updated.',
    'announcementPublished' => 'Transfer announcement published.',
    'announcementClosed' => 'Transfer announcement closed.',
    'announcementCancelled' => 'Transfer announcement cancelled.',
    'announcementDeleted' => 'Transfer announcement deleted.',

    // Application flash messages
    'applicationSubmitted' => 'Your transfer application has been submitted successfully.',
    'applicationScreened' => 'Application screened.',
    'applicationSelected' => 'Candidate selected.',
    'applicationRejected' => 'Application rejected.',
    'applicationWithdrawn' => 'Application withdrawn.',
    'releaseApproved' => 'Release approved.',
    'releaseRejected' => 'Release rejected.',
    'receivingApproved' => 'Receiving approved.',
    'receivingRejected' => 'Receiving rejected.',
    'finalApproved' => 'Final approval granted.',
    'finalRejected' => 'Final approval rejected.',
    'noEmployeeProfile' => 'No employee profile is linked to your account. Please contact HR.',
    'alreadyApplied' => 'You have already applied for this transfer announcement.',
    'notAcceptingApplications' => 'This announcement is not currently accepting applications.',

    // Status transition errors
    'cancelNotAllowed' => 'Only draft or published announcements can be cancelled.',

    // Publish validation errors
    'announcementNotDraft' => 'Only draft announcements can be published.',
    'announcementNotPublished' => 'Only published announcements can be closed.',
    'publishMissingOrganization' => 'The announcement must have an organization before it can be published.',
    'publishMissingPosition' => 'The announcement must have a position before it can be published.',
    'publishNoVacancies' => 'The announcement must have at least one vacancy.',
    'publishMissingDates' => 'Opening date and closing date are required before publishing.',
    'publishInvalidDateRange' => 'Closing date must be after opening date.',
    'publishNoEstablishment' => 'No approved establishment found for the selected position and organization.',
];
