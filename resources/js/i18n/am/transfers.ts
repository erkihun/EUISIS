const transfers = {
    // Module
    module: 'የዝውውር አስተዳደር',
    dashboard: 'የዝውውር ዳሽቦርድ',
    settings: 'የዝውውር ቅንብሮች',
    settingsUpdated: 'የዝውውር ቅንብሮች ዘምነዋል።',
    tabRules: 'የዝውውር ደንቦች',
    tabApproval: 'የፈቃድ ሰንሰለት',
    tabDocuments: 'የሚያስፈልጉ ሰነዶች',
    tabOverride: 'ልዩ ፈቃድ ፖሊሲ',
    tabPostTransfer: 'ከዝውውር በኋላ',

    // Announcements
    announcements: 'የዝውውር ማስታወቂያዎች',
    announcement: 'የዝውውር ማስታወቂያ',
    announcementCreated: 'የዝውውር ማስታወቂያ ተፈጥሯል።',
    announcementUpdated: 'የዝውውር ማስታወቂያ ዘምኗል።',
    announcementPublished: 'የዝውውር ማስታወቂያ ታትሟል።',
    announcementClosed: 'የዝውውር ማስታወቂያ ተዘጋ።',
    announcementCancelled: 'የዝውውር ማስታወቂያ ተሰርዟል።',
    announcementDeleted: 'የዝውውር ማስታወቂያ ተሰርዟል።',
    createAnnouncement: 'ማስታወቂያ ፍጠር',
    cancelAnnouncement: 'ሰርዝ',
    editAnnouncement: 'ማስታወቂያ አርትዕ',
    publishAnnouncement: 'አትም',
    closeAnnouncement: 'ዝጋ',
    deleteAnnouncement: 'ሰርዝ',

    // Applications
    applications: 'ማመልከቻዎች',
    application: 'ማመልከቻ',
    applicationSubmitted: 'ማመልከቻ ቀርቧል።',
    applicationScreened: 'ማመልከቻ ወደ ምርምር ተዛወረ።',
    applicationSelected: 'ተወዳዳሪ ተመርጧል።',
    applicationRejected: 'ማመልከቻ ውድቅ ሆኗል።',
    applicationWithdrawn: 'ማመልከቻ ተቀልፏል።',
    applyForTransfer: 'ለዝውውር አመልክት',
    withdrawApplication: 'ማመልከቻ አንሳ',
    myApplications: 'ማመልከቻዎቼ',

    // Approval
    releaseApproved: 'መልቀቂያ ፀደቀ።',
    releaseRejected: 'መልቀቂያ ውድቅ ሆኗል።',
    receivingApproved: 'መቀበያ ፀደቀ።',
    receivingRejected: 'መቀበያ ውድቅ ሆኗል።',
    finalApproved: 'የመጨረሻ ፈቃድ ተሰጠ።',
    finalRejected: 'የመጨረሻ ፈቃድ ውድቅ ሆኗል።',
    approveRelease: 'መልቀቂያ አፅድቅ',
    rejectRelease: 'መልቀቂያ አውድቅ',
    approveReceiving: 'መቀበያ አፅድቅ',
    rejectReceiving: 'መቀበያ አውድቅ',
    approveFinal: 'የመጨረሻ ፈቃድ ስጥ',
    rejectFinal: 'የመጨረሻ ፈቃድ አውድቅ',

    // Status labels
    statusDraft: 'ረቂቅ',
    statusPublished: 'ታትሟል',
    statusClosed: 'ተዘጋ',
    statusCancelled: 'ተሰርዟል',
    statusSubmitted: 'ቀርቧል',
    statusUnderReview: 'በምርምር ላይ',
    statusVerified: 'ተረጋግጧል',
    statusSelected: 'ተመርጧል',
    statusReleasePending: 'መልቀቂያ በጥበቃ',
    statusReceivingPending: 'መቀበያ በጥበቃ',
    statusFinalApprovalPending: 'የመጨረሻ ፈቃድ በጥበቃ',
    statusApproved: 'ፀድቋል',
    statusTransferred: 'ተዛወሯል',
    statusRejected: 'ውድቅ ሆኗል',
    statusWithdrawn: 'ተቀልፏል',

    // Fields
    organization: 'ተቋም',
    position: 'ቦታ',
    gradeLevel: 'ደረጃ',
    salaryMin: 'ዝቅተኛ ደሞዝ',
    salaryMax: 'ከፍተኛ ደሞዝ',
    numberOfVacancies: 'የክፍት ቦታ ብዛት',
    eligibilityRules: 'የብቃት መስፈርቶች',
    requiredDocuments: 'የሚያስፈልጉ ሰነዶች',
    openingDate: 'የመጀመሪያ ቀን',
    closingDate: 'የመዘጊያ ቀን',
    releasingOrganization: 'ሠራተኛው ያለው ተቋም',
    receivingOrganization: 'ሠራተኛው የሚሄደው ተቋም',
    applicantNotes: 'ማስታወሻ',
    rejectedReason: 'የውድቅ ምክንያት',

    // Settings fields
    requireSamePosition: 'አንድ አይነት ቦታ ያስፈልጋል',
    requireSameGrade: 'አንድ አይነት ደረጃ ያስፈልጋል',
    requireSameSalary: 'አንድ አይነት ደሞዝ ያስፈልጋል',
    allowCrossInstitution: 'ተቋምን አቋርጦ ዝውውር ይፈቀዳል',
    allowExceptionalOverride: 'ልዩ ሁኔታ ፈቃድ ይፈቀዳል',
    overrideApproverRoles: 'ልዩ ፈቃድ ሰጪ ሚናዎች',
    minimumServiceMonths: 'ዝቅተኛ አገልግሎት (ወር)',
    releasingConsentRequired: 'የሚለቀቀው ተቋም ስምምነት ያስፈልጋል',
    receivingConsentRequired: 'ሠራተኛውን ሚቀበለው ተቋም ስምምነት ያስፈልጋል',
    finalApprovalRequired: 'የሚኒስቴር/ቢሮ የመጨረሻ ፈቃድ ያስፈልጋል',
    cardReprintPolicy: 'ካርድ ዳግም ህትመት ፖሊሲ',
    serviceRecalculationPolicy: 'አገልግሎት ዳግም ስሌት ፖሊሲ',

    // Dashboard stats
    activeAnnouncements: 'ንቁ ማስታወቂያዎች',
    pendingApplications: 'በጥበቃ ላይ ያሉ ማመልከቻዎች',
    releasePending: 'መልቀቂያ ሲጠበቅ',
    receivingPending: 'መቀበያ ሲጠበቅ',
    finalPending: 'የመጨረሻ ፈቃድ ሲጠበቅ',
    recentTransfers: 'የቅርብ ጊዜ ዝውውሮች',

    // Screening
    screen: 'ምርምር ጀምር',
    select: 'ዕጩ ምረጥ',
    reject: 'አውድቅ',
    notes: 'ማስታወሻ',
    reviewHistory: 'የምርምር ታሪክ',

    // Approvals section
    approvalChain: 'የፈቃድ ሰንሰለት',
    releaseApproval: 'የመልቀቂያ ፈቃድ',
    receivingApproval: 'የመቀበያ ፈቃድ',
    finalApproval: 'የመጨረሻ ፈቃድ',

    // Common
    noAnnouncements: 'ምንም ዝውውር ማስታወቂያ አልተገኘም።',
    noApplications: 'ምንም ዝውውር ማመልከቻ አልተገኘም።',
    addEligibilityRule: 'ደንብ ጨምር',
    addDocument: 'ሰነድ ዓይነት ጨምር',
    addRole: 'ሚና ጨምር',
    applicationsCount: 'ማመልከቻዎች',
    vacancies: 'ክፍት ቦታዎች',

    // Multi-position create form
    positionLine: 'ቦታ',
    addPosition: 'ቦታ ጨምር',
    totalVacancies: 'ጠቅላላ ክፍት ቦታዎች',
    availableSlots: 'ዝርዝር ክፍት ቦታ',
    noPositionSelected: 'ቦታ ይምረጡ',
    multiInstitutionHint: 'አንድ ማስታወቂያ ከብዙ ተቋሞች ያሉ ክፍት ቦታዎችን ሊሸፍን ይችላል።',

    // Card reprint policy options
    cardReprintNoReprint: 'ዳግም ህትመት አይደረግም',
    cardReprintRequestReprint: 'ዳግም ህትመት ጥያቄ ፍጠር',
    cardReprintAutoReprint: 'ራስ-ሰር ዳግም አትም',

    // Service recalculation policy options
    serviceRecalcNone: 'ዳግም ስሌት አይደረግም',
    serviceRecalcFromTransfer: 'ከዝውውር ቀን ዳግም አሰላ',
    serviceRecalcFromEffective: 'ከሥራ መጀመሪያ ቀን ዳግም አሰላ',

    // Approval type labels
    approvalTypeRelease: 'የመልቀቂያ ፈቃድ',
    approvalTypeReceiving: 'የመቀበያ ፈቃድ',
    approvalTypeFinal: 'የመጨረሻ ፈቃድ',

    // Screening action labels
    screenActionUnderReview: 'ወደ ምርምር ተዛወረ',
    screenActionVerified: 'ተረጋግጧል',
    screenActionSelected: 'ተመርጧል',
    screenActionRejected: 'ውድቅ ሆኗል',
    screenActionSubmitted: 'ቀርቧል',

    // Public announcement detail & apply pages
    statusOpen: 'ክፍት',
    // Used by the portal pages; previously only present in the PHP lang files,
    // so these screens rendered the raw key.
    noEmployeeProfile: 'ከአካውንትዎ ጋር የተያያዘ የሠራተኛ መገለጫ የለም። እባክዎ ከ HR ጋር ያነጋግሩ።',
    salary: 'ደሞዝ',
    eligibilityRequirements: 'የብቃት መስፈርቶች',
    notAcceptingApplicationsInfo: 'ይህ ማስታወቂያ ማመልከቻዎችን መቀበል አቁሟል።',
    applicationAlreadySubmitted: 'ማመልከቻ ቀርቧል',
    applicationUnderReview: 'ማመልከቻዎ በምርምር ላይ ነው።',
    signInToApply: 'ለማመልከት ይግቡ',
    closingOn: 'ዝጊያ',
    closes: 'ዝጊያ',
    backToAnnouncement: 'ወደ ማስታወቂያ ተመለስ',
    coverLetter: 'የምክንያት ደብዳቤ / ለዝውውር ምክንያት',
    coverLetterPlaceholder: 'ለዚህ ዝውውር ለምን እንደሚያመለክቱ፣ ተዛማጅ ልምድዎን እና ለአዲሱ ቦታ ምን አስተዋፅዖ ሊያደርጉ እንደሚችሉ ያብራሩ…',
    supportingDocuments: 'ተጓዳኝ ሰነዶች',
    documentUploadHint: 'አማራጭ — PDF፣ JPG፣ PNG፣ ከፍተኛ 5 MB',
    clickToUpload: 'ሰነዶች ለመጫን ጠቅ ያድርጉ',
    removeFile: 'ፋይሉን አስወግድ',
    submitApplication: 'ማመልከቻ አስገባ',
    submitting: 'እያስገባ ነው…',
} as const;

export default transfers;
