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
    currentPlanUsage: 'Current Plan Usage',
    currentPlanItems: '{count} plan items',
    notInCurrentPlan: 'Not in a current plan',
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

    // Admin — shared shell
    overview: 'Overview',
    inbox: 'Inbox',
    sectionsNav: 'Feedback sections',
    inboxSubtitle: 'Read, review and resolve what clients said.',
    reportsSubtitle: 'Who and what needs attention, ranked lowest average first.',
    exportCsv: 'Export CSV',
    reviewPending: 'Review pending',

    // Admin — filters
    searchLabel: 'Search feedback',
    searchPlaceholder: 'Search comments, client names or employees…',
    allOrganizations: 'All organizations',
    allServiceTypes: 'All service types',
    dateFrom: 'From',
    dateTo: 'To',
    filteredBy: 'Filtered by',
    clearAll: 'Clear all',
    removeFilter: 'Remove filter',
    period: 'Period',
    last7Days: '7 days',
    last30Days: '30 days',
    last90Days: '90 days',
    allTime: 'All time',

    // Admin — overview
    inSelectedScope: 'In the selected scope',
    outOfFive: 'out of 5',
    lowRatings: 'Low ratings (1–2★)',
    shareOfResponses: ':percent% of responses',
    awaitingReview: 'Awaiting review',
    oldestWaitingSince: 'Oldest since',
    openInbox: 'Open inbox',
    allCaughtUp: 'All caught up',
    watchlist: 'Watchlist',
    satisfiedShare: ':percent% satisfied (4–5★)',
    satisfied: 'Satisfied',
    neutral: 'Neutral',
    dissatisfied: 'Dissatisfied',
    needsAttention: 'Needs attention',
    nothingNeedsAttention: 'No low ratings are waiting for review.',
    awaitingReviewCount: ':count awaiting review',
    goToInbox: 'Go to inbox',
    topByVolume: 'Top by volume',
    averageAndCount: 'Avg · responses',
    viewAllInInbox: 'View all in inbox',

    // Admin — inbox
    statusAll: 'All',
    columnFeedback: 'Feedback',
    noCommentRatingOnly: 'No comment — rating only',
    openFeedback: 'Open feedback',
    showingRange: 'Showing :from–:to of :total',
    nothingHere: 'Nothing here',
    noMatches: 'No feedback matches these filters.',
    pagination: 'Feedback pages',

    // Admin — detail
    feedbackFor: 'Feedback for :name',
    viaQr: 'via QR code',
    clientRating: 'Client rating',
    client: 'Client',
    contact: 'Contact',
    notProvided: 'Not provided',
    review: 'Review',
    reviewHint: 'Record what was done. The note is visible to administrators only — never to the employee or the client.',
    outcome: 'Outcome',
    reviewedHint: 'Read and noted. No further action needed.',
    resolvedHint: 'The issue was followed up and closed.',
    optional: 'optional',
    reviewNotePlaceholder: 'e.g. Spoke with the branch manager; signage for the window was ordered.',
    serviceProvidedBy: 'Service provided by',
    activity: 'Activity',
    submittedByClient: 'Submitted by client',
    awaitingReviewDetail: 'No one has reviewed this yet',
    moderation: 'Moderation',
    hideComment: 'Hide comment',
    restoreComment: 'Restore comment',
    hideHint: 'Hiding removes it from the comment feed and employee views. It stays in reports and can be restored.',
    restoreHint: 'Restoring returns it to the comment feed and back to pending review.',
    hiddenNotice: 'This comment is hidden from the comment feed and employee views.',
    deletePermanently: 'Delete permanently',
    deleteHint: 'Deleting cannot be undone. The audit log keeps a record of it.',

    // Admin — reports
    lowestFirstHint: 'Lowest average first — the desks that need attention rise to the top.',
    groupBy: 'Group by',
    responses: 'Responses',
    lowShort: 'Low (1–2★)',
    lowRatingWatchlist: 'Low-rating watchlist',
    noLowRatings: 'No low ratings in this scope.',
};
