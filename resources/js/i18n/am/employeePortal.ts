/**
 * Employee self-service portal (/my-portal) — Amharic.
 */
const employeePortal = {
    title: 'የእኔ ዳሽቦርድ',

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
    myServices: 'ጥቅማጥቅሞች',
    active: 'ንቁ',
    noActiveCard: 'ንቁ የመታወቂያ ካርድ የለም።',
    quotaUsed: ':used / :limit ጥቅም ላይ ውሏል',
    until: 'እስከ',
    viewAll: 'ሁሉንም ይመልከቱ',

    // Weekday initials for the subsidy strip.
    weekdays: { mon: 'ሰኞ', tue: 'ማክሰ', wed: 'ረቡዕ', thu: 'ሐሙስ', fri: 'ዓርብ' },
    // Dashboard
    welcome: 'እንኳን ደህና መጡ፣ :name',
    attentionTitle: 'እርምጃዎን የሚፈልጉ',
    allClear: 'ሁሉም ተጠናቋል። አሁን እርምጃዎን የሚፈልግ ነገር የለም።',
    todoRegisterToday: 'የዛሬውን ዕለታዊ እንቅስቃሴ ይመዝግቡ',
    todoFinishDraft: 'የዛሬውን ዕለታዊ እንቅስቃሴ አጠናቀው ያስገቡ',
    todoReturned: 'ለእርማት የተመለሰ ዕለታዊ እንቅስቃሴ፡ :count',
    todoMissing: 'በዚህ ሳምንት እንቅስቃሴ ያልቀረበባቸው የሥራ ቀናት፡ :count',
    todoReprint: 'መታወቂያ ካርድዎ እንደገና መታተም አለበት',
    todoUnread: 'ያልተነበቡ ማሳወቂያዎች፡ :count',
    todoPendingRequests: 'የሰው ሀብትን የሚጠብቁ የማስተካከያ ጥያቄዎች፡ :count',
    // የእኔ አፈጻጸም (EPMS) በዳሽቦርዱ ላይ
    myPerformance: 'የእኔ አፈጻጸም',
    todoAcknowledgeAgreement: 'የአፈጻጸም ስምምነትዎን ገምግመው ያረጋግጡ',
    todoSelfAssessmentMidYear: 'የአጋማሽ ዓመት ራስ-ግምገማዎን ያቅርቡ',
    todoSelfAssessmentYearEnd: 'የዓመት መጨረሻ ራስ-ግምገማዎን ያቅርቡ',
    todoReviewReturnedMidYear: 'የአጋማሽ ዓመት ራስ-ግምገማዎ ለማስተካከያ ተመልሷል',
    todoReviewReturnedYearEnd: 'የዓመት መጨረሻ ራስ-ግምገማዎ ለማስተካከያ ተመልሷል',
    todoResultReleased: 'የአፈጻጸም ውጤትዎ ይፋ ሆኗል',
    kpiCount: ':count KPIዎች',
    kpiOnTrack: ':count በመስመር ላይ',
    kpiAttention: ':count ትኩረት የሚሹ',
    kpiNotReported: ':count ገና ያልተዘገቡ',
    noPerformanceAgreement: 'እስካሁን የአፈጻጸም ስምምነት የለዎትም። የአፈጻጸም ዑደቱ ሲከፈት ኃላፊዎ ያዘጋጃል።',
    noAgreementShort: 'ገና ስምምነት የለም',
    open: 'ክፈት',
    todayActivity: 'የዛሬ እንቅስቃሴ',
    weekActivity: 'የዚህ ሳምንት እንቅስቃሴ',
    submittedOf: 'ከ:required ቀናት :submitted ቀርበዋል',
    missingCount: ':count የጎደለ',
    unreadMessages: 'ያልተነበቡ መልዕክቶች',
    dailyActivity: 'ዕለታዊ እንቅስቃሴ',
    registerActivity: 'እንቅስቃሴ ይመዝግቡ',
    activityItems: ':count እንቅስቃሴዎች',
    notifications: 'ማሳወቂያዎች',
    noNotifications: 'ገና ማሳወቂያ የለም።',
    myProfile: 'የእኔ መገለጫ',
    reprintRequired: 'እንደገና ማተም ያስፈልጋል',
    // My Services
    myServicesIntro: 'የሚገቡዎት አገልግሎቶች እና እንዴት እንደተጠቀሙባቸው።',
    inactive: 'ንቁ ያልሆኑ',
    ridesThisMonth: 'በዚህ ወር የተደረጉ ጉዞዎች',
    transportPass: 'የትራንስፖርት ፓስ',
    transportPasses: 'የትራንስፖርት ፓሶች',
    noTransportPass: 'እስካሁን የትራንስፖርት ፓስ አልተሰጠም።',
    recentRides: 'የቅርብ ጉዞዎች',
    noRides: 'እስካሁን የተመዘገበ ጉዞ የለም።',
    statuses: {
        active: 'ንቁ', paused: 'ለጊዜው የቆመ', suspended: 'የታገደ', revoked: 'የተሰረዘ', expired: 'ጊዜው ያለፈበት',
        exhausted: 'ያለቀ', accepted: 'ተቀባይነት ያገኘ', rejected: 'ውድቅ የተደረገ', completed: 'የተጠናቀቀ',
        reversed: 'የተመለሰ', pending: 'በመጠባበቅ ላይ', cancelled: 'የተሰረዘ',
    },
    // Dashboard sections
    recentDays: 'የቅርብ ቀናት',
    noActivityYet: 'እስካሁን የተመዘገበ ዕለታዊ እንቅስቃሴ የለም።',
    upcomingHolidays: 'የሚመጡ ሕዝባዊ በዓላት',
    noHolidays: 'በሚቀጥሉት ጥቂት ወራት ሕዝባዊ በዓል የለም።',
    myRequests: 'የእኔ ጥያቄዎች',
    noRequests: 'እስካሁን የማስተካከያ ጥያቄ አላቀረቡም።',
    newRequest: 'አዲስ ጥያቄ',
    cardNumber: 'የካርድ ቁጥር',
    issued: 'የተሰጠበት',
    expires: 'የሚያበቃበት',
    activeBenefits: 'ንቁ ጥቅማጥቅሞች',
    requestStatuses: { pending: 'የሰው ሀብትን በመጠባበቅ ላይ', approved: 'ጸድቋል', rejected: 'ውድቅ ተደርጓል' },
    correctionFields: {
        full_name: 'ሕጋዊ ስም', date_of_birth: 'የትውልድ ቀን', national_id: 'ብሔራዊ መታወቂያ', nationality: 'ዜግነት',
        employee_number: 'የሠራተኛ ቁጥር', employment_type: 'የቅጥር ዓይነት', gender: 'ጾታ',
    },
} as const;

export default employeePortal;
