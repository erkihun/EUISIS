/**
 * Client Service Feedback.
 *
 * Covers both the anonymous public form a client reaches by scanning an
 * employee's feedback QR, and the administrative review screens.
 *
 * The public strings never name the employee — the page identifies the desk by
 * office, unit and role only — so wording here talks about "the service you
 * received" rather than about a person.
 */
export default {
    // Module + navigation
    title: 'Service Feedback',
    clientFeedback: 'Client Feedback',
    moduleSubtitle: 'Client ratings and comments on services provided by employees.',

    // Public page
    publicTitle: 'Feedback and Suggestion',
    publicIntro: 'You are giving feedback for the service provided at this desk.',
    publicPrivacyNote: 'Your feedback is anonymous unless you choose to add your contact details.',
    serviceOffice: 'Office',
    serviceUnit: 'Unit',
    servicePosition: 'Position',

    // Form
    serviceType: 'Service Type',
    serviceTypeOrId: 'Service Type / Service ID',
    serviceIdNo: 'Service ID/No',
    positionServices: 'Position Services',
    serviceName: 'Service Name',
    serviceNameEn: 'Service Name (English)',
    serviceNameAm: 'Service Name (Amharic)',
    serviceDescription: 'Description',
    positionServicesHint: 'Services each position provides. These appear on the public feedback form for employees holding that position.',
    addPositionService: 'Add Service to Position',
    editPositionService: 'Edit Position Service',
    noPositionServices: 'No services have been assigned to any position yet.',
    searchServices: 'Search service ID or name…',
    selectOrganization: 'Select an organization',
    selectPosition: 'Select a position',
    position: 'Position',
    sortOrder: 'Display Order',
    activeHint: 'Inactive services are hidden from the public feedback form.',
    performanceHint: 'Include ratings for this service in employee performance evaluation.',
    organizationServiceType: 'Organization Service Type',
    usePerformanceEvaluation: 'Use for Performance Evaluation',
    usedForPerformanceEvaluation: 'This service type is used for performance evaluation',
    serviceIdExists: 'Service ID already exists for this organization',
    serviceTypeLockedAfterFeedback: 'Service type cannot be changed after feedback exists',
    serviceTypeWrongOrganization: 'Service type does not belong to this organization',
    noServiceTypesConfigured: 'No feedback service types configured for this organization.',
    manageServiceTypes: 'Manage Service Types',
    serviceTypePlaceholder: 'Select the service you received',
    satisfactionRating: 'Satisfaction Rating',
    ratingHint: 'Tap a star to rate the service from 1 to 5.',
    comment: 'Comment',
    commentPlaceholder: 'Tell us about your experience (optional)',
    clientName: 'Your Name',
    clientNameHint: 'Optional',
    clientContact: 'Phone or Email',
    clientContactHint: 'Optional — only if you would like a response',
    submitFeedback: 'Submit Feedback',
    submitting: 'Submitting…',

    // Ratings
    rating1: 'Very Dissatisfied',
    rating2: 'Dissatisfied',
    rating3: 'Neutral',
    rating4: 'Satisfied',
    rating5: 'Very Satisfied',

    // Outcomes
    feedbackSubmitted: 'Feedback submitted successfully',
    feedbackSubmittedDetail: 'Thank you. Your feedback helps improve public service.',
    submitAnother: 'Submit another response',
    linkUnavailable: 'This feedback link is not available',
    linkUnavailableDetail:
        'The link may have expired or been replaced. Please ask for the current feedback QR code at the service desk.',
    tooManySubmissions: 'Too many submissions from this device. Please try again later.',

    // Admin — dashboard
    dashboard: 'Feedback Dashboard',
    totalFeedback: 'Total Feedback',
    averageRating: 'Average Rating',
    ratingDistribution: 'Rating Distribution',
    feedbackByOrganization: 'Feedback by Organization',
    feedbackByEmployee: 'Feedback by Employee',
    feedbackByServiceType: 'Feedback by Service Type',
    recentComments: 'Recent Comments',
    noFeedbackYet: 'No feedback has been submitted yet.',

    // Admin — list & detail
    feedbackList: 'Feedback List',
    feedbackDetail: 'Feedback Detail',
    submittedDate: 'Submitted',
    reviewedBy: 'Reviewed By',
    reviewNote: 'Review Note',
    anonymousClient: 'Anonymous',

    // Filters
    filterOrganization: 'Organization',
    filterUnit: 'Organization Unit',
    filterEmployee: 'Employee',
    filterServiceType: 'Service Type',
    filterRating: 'Rating',
    filterStatus: 'Status',
    filterDateRange: 'Date Range',
    allRatings: 'All ratings',
    allStatuses: 'All statuses',

    // Status
    statusPending: 'Pending',
    statusReviewed: 'Reviewed',
    statusResolved: 'Resolved',
    statusHidden: 'Hidden',

    // Actions
    markReviewed: 'Mark Reviewed',
    markResolved: 'Mark Resolved',
    hideFeedback: 'Hide',
    unhideFeedback: 'Unhide',
    deleteFeedback: 'Delete',
    exportFeedback: 'Export',

    // QR management
    employeeFeedbackQr: 'Employee Feedback QR',
    feedbackQrDescription:
        'Clients scan this code to rate the service this employee provides. It contains no personal information.',
    generateQr: 'Generate QR',
    regenerateQr: 'Regenerate QR',
    revokeQr: 'Revoke QR',
    printQr: 'Print QR',
    exportQrPng: 'Export PNG',
    exportQrPdf: 'Export PDF',
    qrActive: 'Active',
    qrSuspended: 'Suspended',
    qrRevoked: 'Revoked',
    qrNotGenerated: 'No feedback QR has been generated for this employee.',
    qrDisabledByAdmin: 'This feedback QR was revoked or suspended by an administrator. Generate a new one to make it scannable again.',
    qrInactiveEmployee: 'A feedback QR is issued only for active employees. This employee is not currently active.',
    copyLink: 'Copy Link',
    linkCopied: 'Copied',
    regenerateQrWarning:
        'Regenerating replaces the current code. Any printed QR already in circulation will stop working.',
    feedbackCount: 'Feedback Received',
    lastScanned: 'Last Scanned',

    // Reports
    reports: 'Feedback Reports',
    lowRatingReport: 'Low Rating Report',
    averageRatingByEmployee: 'Average Rating by Employee',
    averageRatingByOrganization: 'Average Rating by Organization',
    serviceTypePerformance: 'Service Type Performance',
};
