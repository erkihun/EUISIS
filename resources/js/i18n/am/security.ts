const security = {
    sessionExpired: 'የክፍለ ጊዜዎ አብቅቷል። እባክዎ እንደገና ይግቡ።',
    accessDenied: 'ፍቃድ የለም። ይህን ድርጊት ለማከናወን ፈቃድ የለዎትም።',
    scopeDenied: 'የተቋም ወሰንዎ ይህን መዝገብ አይጨምርም።',
    tooManyAttempts: 'ብዙ ሙከራዎች። እባክዎ ጠብቀው እንደገና ይሞክሩ።',
    strongPasswordRequired: 'የሚስጥር ቃል ዝቅተኛ የደህንነት መስፈርቶችን ማሟላት አለበት።',
    securitySettingsUpdated: 'የደህንነት ቅንብሮች በተሳካ ሁኔታ ተዘምነዋል።',
    auditLog: 'የኦዲት ምዝግብ ማስታወሻ',
    incidentResponse: 'የአደጋ ምላሽ',
    vulnerabilityManagement: 'የተጋላጭነት አያያዝ',

    // ምዝገባ ቁጥጥር
    registrationDisabled: 'ምዝገባ ተሰናክሏል። የተጠቃሚ መለያዎች በአስተዳዳሪዎች ብቻ ይፈጠራሉ።',

    // MFA
    mfaRequired: 'ለመለያዎ የብዙ-ደረጃ ማረጋገጫ ያስፈልጋል።',
    mfaChallengeFailed: 'የተሳሳተ የማረጋገጫ ኮድ።',
    mfaEnabled: 'የብዙ-ደረጃ ማረጋገጫ ነቅቷል።',
    mfaDisabled: 'የብዙ-ደረጃ ማረጋገጫ ተሰናክሏል።',
    mfaAlreadyEnabled: 'የብዙ-ደረጃ ማረጋገጫ አስቀድሞ ነቅቷል።',
    mfaDisableNotAllowed: 'ለሚናዎ የብዙ-ደረጃ ማረጋገጫን ማሰናከል አይቻልም።',
    mfaSetupRequired: 'ለመቀጠል እባክዎ የብዙ-ደረጃ ማረጋገጫ ቅንብርን ያጠናቅቁ።',
    mfaChallengeRequired: 'ለመቀጠል እባክዎ የማረጋገጫ ኮድዎን ያረጋግጡ።',
    mfaRecoveryCodesNote: 'እነዚህን የመልሶ ማግኛ ኮዶች በደህንነቱ በተጠበቀ ቦታ ያስቀምጡ። እያንዳንዱ ኮድ አንድ ጊዜ ብቻ ይጠቀምበት ይችላል።',

    // INSA-required የደህንነት መልዕክቶች
    uploadRejected: 'ፋይል መጫን ተከልክሏል።',
    unsafeFileType: 'የፋይሉ አይነት አይፈቀድም። የተፈቀዱ አይነቶች ብቻ መጫን ይቻላል።',
    rateLimitExceeded: 'የጥያቄ ገደቡ ተሻገረ። እባክዎ ጥቂት ጠብቀው እንደገና ይሞክሩ።',
    sensitiveInfoProtected: 'ሚስጥራዊ መረጃ ተጠብቋል እና ሊወጣ አይችልም።',
    orgScopeDenied: 'የተቋም ወሰንዎ ይህን መዝገብ አይጨምርም።',
    actionNotPermitted: 'ይህ ድርጊት ለመለያዎ አይፈቀድም።',
    securityHeadersEnabled: 'የደህንነት ራስጌዎች ነቅተዋል።',
    secureConfigRequired: 'ለዚህ አካባቢ ደህንነቱ የተጠበቀ ቅንብር ያስፈልጋል።',
    invalidRequest: 'ትክክል ያልሆነ ጥያቄ። እባክዎ ግቤቱን ያረጋግጡና እንደገና ይሞክሩ።',
    unauthorized: 'ይህን ሀብት ለማግኘት ወደ ስርዓቱ መግባት አለቦት።',
    forbidden: 'ይህን ድርጊት ለመፈጸም ፈቃድ የለዎትም።',
    securityPolicy: 'የደህንነት ፖሊሲ',
    piiViewDenied: 'ሚስጥራዊ የግል መረጃ ለማየት ፈቃድ የለዎትም።',
    securityAudit: 'የደህንነት ምርመራ',
} as const;

export default security;
export type SecurityTranslationKeys = typeof security;
