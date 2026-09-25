/**
 * Employee self-service portal (/my-portal).
 *
 * The portal is the one screen an ordinary employee uses, so every string it
 * shows belongs here rather than being written into the page.
 */
const employeePortal = {
    title: 'My Dashboard',

    // Unlinked account
    noProfileTitle: 'No employee profile is linked to your account.',
    noProfileBody: 'Your sign-in address does not match any employee record. Contact HR to have your account linked.',

    // Top stats
    cafeBalance: 'Café Balance',
    daysLeft: 'Days Left',
    idCard: 'ID Card',
    activeApps: 'Active Apps',
    transferApplicationsCaption: 'transfer applications',
    thisWeek: 'this week',
    perDay: ':amount ETB/day',
    currency: 'ETB',
    noCard: 'No card',
    expiresShort: 'Exp.',

    // Cafeteria
    cafeteriaSubsidy: 'Cafeteria Subsidy',
    weekRange: 'Week :from – :to',
    legendUsed: 'Used',
    legendAvailable: 'Available',
    dailyRate: 'Daily Rate',
    weekRemaining: 'Week Remaining',
    balance: 'Balance',
    recentTransactions: 'Recent Transactions',
    cafeteria: 'Cafeteria',
    subsidyApplied: '-:amount ETB subsidy',
    youPay: 'You pay :amount ETB',

    // Transfers
    myTransferApplications: 'My Transfer Applications',
    noApplications: 'No applications yet.',
    browseAnnouncements: 'Browse open announcements →',
    openAnnouncements: 'Open Announcements',
    gradePrefix: 'Grade :grade',
    gradeShort: 'Gr. :grade',
    vacanciesOpen: ':count open',
    closes: 'Closes',

    // Side column
    myServices: 'My Services',
    active: 'Active',
    noActiveCard: 'No active ID card.',
    quotaUsed: ':used / :limit used',
    until: 'Until',
    viewAll: 'View all',

    // Weekday initials for the subsidy strip.
    weekdays: { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri' },
    // Dashboard
    welcome: 'Welcome back, :name',
    attentionTitle: 'Needs your attention',
    allClear: 'You are all caught up. Nothing needs your action right now.',
    todoRegisterToday: "Register today's daily activity",
    todoFinishDraft: "Finish and submit today's daily activity",
    todoReturned: 'Daily activity returned for correction: :count',
    todoMissing: 'Working days this week without submitted activity: :count',
    todoReprint: 'Your ID Card needs to be reprinted',
    todoUnread: 'Unread notifications: :count',
    todoPendingRequests: 'Correction requests waiting for HR: :count',
    // My Performance (EPMS) on the dashboard
    myPerformance: 'My Performance',
    todoAcknowledgeAgreement: 'Review and acknowledge your performance agreement',
    todoSelfAssessmentMidYear: 'Submit your mid-year self-assessment',
    todoSelfAssessmentYearEnd: 'Submit your year-end self-assessment',
    todoReviewReturnedMidYear: 'Your mid-year self-assessment was returned for changes',
    todoReviewReturnedYearEnd: 'Your year-end self-assessment was returned for changes',
    todoResultReleased: 'Your performance result is available',
    kpiCount: ':count KPIs',
    kpiOnTrack: ':count on track',
    kpiAttention: ':count need attention',
    kpiNotReported: ':count not reported yet',
    noPerformanceAgreement: 'No performance agreement yet. Your manager prepares it when the performance cycle opens.',
    noAgreementShort: 'No agreement yet',
    open: 'Open',
    todayActivity: "Today's activity",
    weekActivity: 'Activity this week',
    submittedOf: ':submitted of :required days submitted',
    missingCount: ':count missing',
    unreadMessages: 'Unread messages',
    dailyActivity: 'Daily Activity',
    registerActivity: 'Register activity',
    activityItems: ':count activities',
    notifications: 'Notifications',
    noNotifications: 'No notifications yet.',
    myProfile: 'My profile',
    reprintRequired: 'Reprint required',
    // My Services
    myServicesIntro: 'The services you are entitled to and how you have used them.',
    inactive: 'Inactive',
    ridesThisMonth: 'Rides this month',
    transportPass: 'Transport pass',
    transportPasses: 'Transport passes',
    noTransportPass: 'No transport pass issued yet.',
    recentRides: 'Recent rides',
    noRides: 'No rides recorded yet.',
    statuses: {
        active: 'Active', paused: 'Paused', suspended: 'Suspended', revoked: 'Revoked', expired: 'Expired',
        exhausted: 'Exhausted', accepted: 'Accepted', rejected: 'Rejected', completed: 'Completed',
        reversed: 'Reversed', pending: 'Pending', cancelled: 'Cancelled',
    },
    // Dashboard sections
    recentDays: 'Recent days',
    noActivityYet: 'No daily activity recorded yet.',
    upcomingHolidays: 'Upcoming public holidays',
    noHolidays: 'No public holidays in the next few months.',
    myRequests: 'My requests',
    noRequests: 'You have not sent any correction requests.',
    newRequest: 'New request',
    cardNumber: 'Card number',
    issued: 'Issued',
    expires: 'Expires',
    activeBenefits: 'Active benefits',
    requestStatuses: { pending: 'Waiting for HR', approved: 'Approved', rejected: 'Rejected' },
    correctionFields: {
        full_name: 'Legal name', date_of_birth: 'Date of birth', national_id: 'National ID', nationality: 'Nationality',
        employee_number: 'Employee number', employment_type: 'Employment type', gender: 'Sex',
    },
} as const;

export default employeePortal;
