/**
 * Employee self-service portal (/my-portal) — Amharic.
 */
const employeePortal = {
    title: 'የእኔ ፖርታል',

    // Unlinked account
    noProfileTitle: 'ከመለያዎ ጋር የተገናኘ የሠራተኛ መዝገብ የለም።',
    noProfileBody: 'የመግቢያ አድራሻዎ ከማንኛውም የሠራተኛ መዝገብ ጋር አይዛመድም። መለያዎ እንዲገናኝ የሰው ኃይል ክፍልን ያነጋግሩ።',

    // Top stats
    cafeBalance: 'የካፌ ቀሪ ሂሳብ',
    daysLeft: 'የቀሩ ቀናት',
    idCard: 'መታወቂያ ካርድ',
    activeApps: 'ንቁ ማመልከቻዎች',
    transferApplicationsCaption: 'የዝውውር ማመልከቻዎች',
    thisWeek: 'በዚህ ሳምንት',
    perDay: ':amount ብር/ቀን',
    currency: 'ብር',
    noCard: 'ካርድ የለም',
    expiresShort: 'ማብቂያ',

    // Cafeteria
    cafeteriaSubsidy: 'የካፌቴሪያ ድጎማ',
    weekRange: 'ሳምንት :from – :to',
    legendUsed: 'ጥቅም ላይ የዋለ',
    legendAvailable: 'የሚገኝ',
    dailyRate: 'የቀን መጠን',
    weekRemaining: 'የሳምንቱ ቀሪ',
    balance: 'ቀሪ ሂሳብ',
    recentTransactions: 'የቅርብ ጊዜ ግብይቶች',
    cafeteria: 'ካፌቴሪያ',
    subsidyApplied: '-:amount ብር ድጎማ',
    youPay: 'እርስዎ :amount ብር ይከፍላሉ',

    // Transfers
    myTransferApplications: 'የእኔ የዝውውር ማመልከቻዎች',
    noApplications: 'እስካሁን ማመልከቻ የለም።',
    browseAnnouncements: 'ክፍት ማስታወቂያዎችን ይመልከቱ →',
    openAnnouncements: 'ክፍት ማስታወቂያዎች',
    gradePrefix: 'ደረጃ :grade',
    gradeShort: 'ደረጃ :grade',
    vacanciesOpen: ':count ክፍት',
    closes: 'የሚዘጋው',

    // Side column
    myServices: 'የእኔ አገልግሎቶች',
    active: 'ንቁ',
    noActiveCard: 'ንቁ የመታወቂያ ካርድ የለም።',
    quotaUsed: ':used / :limit ጥቅም ላይ ውሏል',
    until: 'እስከ',
    viewAll: 'ሁሉንም ይመልከቱ',

    // Weekday initials for the subsidy strip.
    weekdays: { mon: 'ሰኞ', tue: 'ማክሰ', wed: 'ረቡዕ', thu: 'ሐሙስ', fri: 'ዓርብ' },
} as const;

export default employeePortal;
