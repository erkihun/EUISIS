/**
 * Employee self-service portal (/my-portal).
 *
 * The portal is the one screen an ordinary employee uses, so every string it
 * shows belongs here rather than being written into the page.
 */
const employeePortal = {
    title: 'My Portal',

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
} as const;

export default employeePortal;
