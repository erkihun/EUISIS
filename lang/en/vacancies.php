<?php

declare(strict_types=1);

return [
    // Vacancy Announcements
    'announcements' => 'Vacancy Announcements',
    'announcement' => 'Vacancy Announcement',
    'announcementNumber' => 'Announcement Number',
    'titleEn' => 'Title (English)',
    'titleAm' => 'Title (Amharic)',
    'descriptionEn' => 'Description (English)',
    'descriptionAm' => 'Description (Amharic)',
    'vacancySlots' => 'Vacancy Slots',
    'applicationOpensAt' => 'Application Opens At',
    'applicationClosesAt' => 'Application Closes At',
    'eligibilityRules' => 'Eligibility Rules',
    'publishedBy' => 'Published By',
    'publishedAt' => 'Published At',
    'closedBy' => 'Closed By',
    'closedAt' => 'Closed At',

    // Status labels
    'statusDraft' => 'Draft',
    'statusPublished' => 'Published',
    'statusClosed' => 'Closed',
    'statusCancelled' => 'Cancelled',

    // Actions
    'createAnnouncement' => 'Create Announcement',
    'editAnnouncement' => 'Edit Announcement',
    'publishAnnouncement' => 'Publish',
    'closeAnnouncement' => 'Close',
    'deleteAnnouncement' => 'Delete',

    // Success messages
    'created' => 'Vacancy announcement created successfully.',
    'updated' => 'Vacancy announcement updated successfully.',
    'published' => 'Vacancy announcement published successfully.',
    'closed' => 'Vacancy announcement closed.',
    'deleted' => 'Vacancy announcement deleted.',

    // Vacancy Applications
    'applications' => 'Applications',
    'application' => 'Application',
    'applicationNumber' => 'Application Number',
    'appliedAt' => 'Applied At',
    'screeningScore' => 'Screening Score',
    'screeningNotes' => 'Screening Notes',
    'screenedBy' => 'Screened By',
    'screenedAt' => 'Screened At',
    'selectedBy' => 'Selected By',
    'selectedAt' => 'Selected At',
    'rejectionReason' => 'Rejection Reason',

    // Application status labels
    'appStatusSubmitted' => 'Submitted',
    'appStatusWithdrawn' => 'Withdrawn',
    'appStatusScreened' => 'Screened',
    'appStatusShortlisted' => 'Shortlisted',
    'appStatusSelected' => 'Selected',
    'appStatusRejected' => 'Rejected',
    'appStatusTransferred' => 'Transferred',

    // Application actions
    'submitApplication' => 'Apply',
    'withdrawApplication' => 'Withdraw Application',
    'screenApplication' => 'Screen',
    'shortlistApplication' => 'Shortlist',
    'selectApplication' => 'Select',
    'rejectApplication' => 'Reject',
    'initiateTransfer' => 'Initiate Transfer',
    'effectiveDate' => 'Effective Date',

    // Application success messages
    'applicationSubmitted' => 'Application submitted successfully.',
    'applicationWithdrawn' => 'Application withdrawn.',
    'applicationScreened' => 'Application screened successfully.',
    'applicationShortlisted' => 'Application shortlisted.',
    'applicationSelected' => 'Application selected.',
    'applicationRejected' => 'Application rejected.',
    'transferCompleted' => 'Vacancy transfer completed successfully.',

    // Validation errors
    'notAcceptingApplications' => 'This vacancy is not currently accepting applications.',
    'noSlotsRemaining' => 'No vacancy slots remain for this announcement.',
    'alreadyApplied' => 'You have already applied for this vacancy.',
    'establishmentNotApproved' => 'The position establishment must be approved before creating a vacancy.',
    'notDraft' => 'Only draft announcements can be published.',
    'noSlotsAvailable' => 'The establishment has no available slots.',
    'announcementCancelled' => 'The vacancy announcement has been cancelled.',
    'applicationNotSelected' => 'Only selected applications can be transferred.',
    'cannotScreen' => 'This application cannot be screened in its current status.',
    'cannotShortlist' => 'This application cannot be shortlisted in its current status.',
    'cannotSelect' => 'This application cannot be selected in its current status or no slots remain.',
    'cannotReject' => 'This application cannot be rejected in its current status.',

    // Transfer reason
    'transferReason' => 'Transferred via vacancy selection process.',

    // My applications
    'myApplications' => 'My Applications',
    'noApplications' => 'You have not submitted any applications.',

    // Slots info
    'slotsAvailable' => 'Available Slots',
    'slotsTotal' => 'Total Slots',
    'slotsSelected' => 'Selected',
    'includedPositions' => 'Included Positions',
    'addPosition' => 'Add Position',
    'positionLine' => 'Position',
    'totalVacancySlots' => 'Total Vacancy Slots',
    'multiOrganizationHint' => 'One announcement can include transfer vacancies for multiple organizations and positions.',
];
