const transfers = {
    // Module
    module: 'Transfer Management',
    dashboard: 'Transfer Dashboard',
    settings: 'Transfer Settings',
    settingsUpdated: 'Transfer settings updated.',
    tabRules: 'Transfer Rules',
    tabApproval: 'Approval Chain',
    tabDocuments: 'Required Documents',
    tabOverride: 'Override Policy',
    tabPostTransfer: 'Post-Transfer',

    // Announcements
    announcements: 'Transfer Announcements',
    announcement: 'Transfer Announcement',
    announcementCreated: 'Transfer announcement created.',
    announcementUpdated: 'Transfer announcement updated.',
    announcementPublished: 'Transfer announcement published.',
    announcementClosed: 'Transfer announcement closed.',
    announcementCancelled: 'Transfer announcement cancelled.',
    announcementDeleted: 'Transfer announcement deleted.',
    createAnnouncement: 'Create Announcement',
    cancelAnnouncement: 'Cancel',
    editAnnouncement: 'Edit Announcement',
    publishAnnouncement: 'Publish',
    closeAnnouncement: 'Close',
    deleteAnnouncement: 'Delete',

    // Applications
    applications: 'Applications',
    application: 'Application',
    applicationSubmitted: 'Application submitted successfully.',
    applicationScreened: 'Application moved to review.',
    applicationSelected: 'Candidate selected.',
    applicationRejected: 'Application rejected.',
    applicationWithdrawn: 'Application withdrawn.',
    applyForTransfer: 'Apply for Transfer',
    withdrawApplication: 'Withdraw Application',
    myApplications: 'My Transfer Applications',

    // Approval
    releaseApproved: 'Release approved.',
    releaseRejected: 'Release rejected.',
    receivingApproved: 'Receiving approved.',
    receivingRejected: 'Receiving rejected.',
    finalApproved: 'Final approval granted.',
    finalRejected: 'Final approval rejected.',
    approveRelease: 'Approve Release',
    rejectRelease: 'Reject Release',
    approveReceiving: 'Approve Receiving',
    rejectReceiving: 'Reject Receiving',
    approveFinal: 'Final Approve',
    rejectFinal: 'Reject Final',

    // Status labels
    statusDraft: 'Draft',
    statusPublished: 'Published',
    statusClosed: 'Closed',
    statusCancelled: 'Cancelled',
    statusSubmitted: 'Submitted',
    statusUnderReview: 'Under Review',
    statusVerified: 'Verified',
    statusSelected: 'Selected',
    statusReleasePending: 'Release Pending',
    statusReceivingPending: 'Receiving Pending',
    statusFinalApprovalPending: 'Final Approval Pending',
    statusApproved: 'Approved',
    statusTransferred: 'Transferred',
    statusRejected: 'Rejected',
    statusWithdrawn: 'Withdrawn',

    // Fields
    organization: 'Organization',
    position: 'Position',
    gradeLevel: 'Grade Level',
    salaryMin: 'Min Salary',
    salaryMax: 'Max Salary',
    numberOfVacancies: 'Number of Vacancies',
    eligibilityRules: 'Eligibility Rules',
    requiredDocuments: 'Required Documents',
    openingDate: 'Opening Date',
    closingDate: 'Closing Date',
    releasingOrganization: 'Releasing Organization',
    receivingOrganization: 'Receiving Organization',
    applicantNotes: 'Notes',
    rejectedReason: 'Rejection Reason',

    // Settings fields
    requireSamePosition: 'Require Same Position',
    requireSameGrade: 'Require Same Grade Level',
    requireSameSalary: 'Require Same Salary',
    allowCrossInstitution: 'Allow Cross-Institution Transfer',
    allowExceptionalOverride: 'Allow Exceptional Override',
    overrideApproverRoles: 'Override Approver Roles',
    minimumServiceMonths: 'Minimum Service (Months)',
    releasingConsentRequired: 'Releasing Institution Consent Required',
    receivingConsentRequired: 'Receiving Institution Consent Required',
    finalApprovalRequired: 'Final City/Bureau Approval Required',
    cardReprintPolicy: 'Card Reprint Policy',
    serviceRecalculationPolicy: 'Service Recalculation Policy',

    // Dashboard stats
    activeAnnouncements: 'Active Announcements',
    pendingApplications: 'Pending Applications',
    releasePending: 'Release Pending',
    receivingPending: 'Receiving Pending',
    finalPending: 'Final Approval Pending',
    recentTransfers: 'Recent Transfers',

    // Screening
    screen: 'Start Review',
    select: 'Select Candidate',
    reject: 'Reject',
    notes: 'Notes',
    reviewHistory: 'Review History',

    // Approvals section
    approvalChain: 'Approval Chain',
    releaseApproval: 'Release Approval',
    receivingApproval: 'Receiving Approval',
    finalApproval: 'Final Approval',

    // Common
    noAnnouncements: 'No transfer announcements found.',
    noApplications: 'No transfer applications found.',
    addEligibilityRule: 'Add Rule',
    addDocument: 'Add Document Type',
    addRole: 'Add Role',
    applicationsCount: 'Applications',
    vacancies: 'Vacancies',

    // Multi-position create form
    positionLine: 'Position',
    addPosition: 'Add Position',
    totalVacancies: 'Total Vacancies',
    availableSlots: 'Available Slots',
    noPositionSelected: 'Select a position',
    multiInstitutionHint: 'A single announcement can pool vacant positions from multiple institutions.',

    // Card reprint policy options
    cardReprintNoReprint: 'No Reprint',
    cardReprintRequestReprint: 'Create Reprint Request',
    cardReprintAutoReprint: 'Auto Reprint',

    // Service recalculation policy options
    serviceRecalcNone: 'No Recalculation',
    serviceRecalcFromTransfer: 'Recalculate from Transfer Date',
    serviceRecalcFromEffective: 'Recalculate from Effective Date',

    // Approval type labels (used in approval chain display)
    approvalTypeRelease: 'Release Approval',
    approvalTypeReceiving: 'Receiving Approval',
    approvalTypeFinal: 'Final Approval',

    // Screening action labels (used in review history)
    screenActionUnderReview: 'Moved to Review',
    screenActionVerified: 'Verified',
    screenActionSelected: 'Selected',
    screenActionRejected: 'Rejected',
    screenActionSubmitted: 'Submitted',

    // Public announcement detail & apply pages
    statusOpen: 'Open',
    // Used by the portal pages; previously only present in the PHP lang files,
    // so these screens rendered the raw key.
    noEmployeeProfile: 'No employee profile is linked to your account. Please contact HR.',
    salary: 'Salary',
    eligibilityRequirements: 'Eligibility Requirements',
    notAcceptingApplicationsInfo: 'This announcement is no longer accepting applications.',
    applicationAlreadySubmitted: 'Application Submitted',
    applicationUnderReview: 'Your application is under review.',
    signInToApply: 'Sign in to Apply',
    closingOn: 'Closing',
    closes: 'Closes',
    backToAnnouncement: 'Back to announcement',
    coverLetter: 'Cover Letter / Reason for Transfer',
    coverLetterPlaceholder: 'Explain why you are applying for this transfer, your relevant experience, and how you can contribute to the new role…',
    supportingDocuments: 'Supporting Documents',
    documentUploadHint: 'optional — PDF, JPG, PNG, max 5 MB each',
    clickToUpload: 'Click to upload documents',
    removeFile: 'Remove file',
    submitApplication: 'Submit Application',
    submitting: 'Submitting…',
} as const;

export default transfers;
