const grievances = {
    // Module labels
    grievances: 'ቅሬታዎች',
    grievance: 'ቅሬታ',
    myGrievances: 'የእኔ ቅሬታዎች',
    committees: 'ኮሚቴዎች',
    committee: 'ኮሚቴ',
    slaRules: 'የ SLA ደንቦች',
    slaRule: 'የ SLA ደንብ',
    tribunalCases: 'የፍርድ ቤት ጉዳዮች',
    tribunalCase: 'የፍርድ ቤት ጉዳይ',
    category: 'ምድብ',

    // Fields
    referenceNumber: 'የማጣቀሻ ቁጥር',
    subject: 'ርዕሰ ጉዳይ',
    description: 'ዝርዝር ገለጻ',
    organization: 'ተቋም',
    organizationUnit: 'የተቋም ክፍል',
    originLevel: 'የምዝገባ ደረጃ',
    status: 'ሁኔታ',
    submittedAt: 'የቀረበበት ቀን',
    submittedBy: 'ያቀረበው',
    members: 'አባላት',
    activeMembersCount: 'ንቁ አባላት',
    memberRole: 'ሚና',
    committeeType: 'የኮሚቴ አይነት',
    effectiveFrom: 'ከ',
    effectiveTo: 'እስከ',
    escalationFromType: 'ከ ደረጃ',
    escalationToType: 'ወደ ደረጃ',
    workingDaysLimit: 'የሥራ ቀናት ገደብ',
    escalations: 'ወደ ላይ ማሳለፍ',
    escalationReason: 'ምክንያት',
    revisionRound: 'ዙር',
    response: 'ምላሽ',
    responseBodyEn: 'ምላሽ (እንግሊዝኛ)',
    responseBodyAm: 'ምላሽ (አማርኛ)',
    rejectionReason: 'የውድቅ ምክንያት',
    requirementNotes: 'የሰነድ ማስታወሻ',
    caseNumber: 'የጉዳይ ቁጥር',
    hearingDate: 'የሰሚ ቀን',
    decisionDate: 'የውሳኔ ቀን',
    decisionSummary: 'የውሳኔ ማጠቃለያ',
    decisionLetter: 'የውሳኔ ደብዳቤ',
    letterReference: 'ደብዳቤ ቁጥር',
    generatedAt: 'የተዘጋጀበት ቀን',

    // Actions
    submitGrievance: 'ቅሬታ አስገባ',
    assignCommittee: 'ኮሚቴ መድብ',
    compileResponse: 'ምላሽ አጠናቅር',
    approveResponse: 'ምላሽ አጽድቅ',
    rejectResponse: 'ምላሽ ውድቅ አድርግ',
    markFulfilled: 'ሰነድ ተሟልቷል',
    markIncomplete: 'ሰነድ አልተሟላም',
    checkRequirements: 'ሰነዶች ፈትሽ',
    addMember: 'አባል ጨምር',
    removeMember: 'አስወግድ',
    downloadLetter: 'ደብዳቤ አውርድ',

    // Filters
    filterByOrganization: 'ተቋም',
    filterByStatus: 'ሁኔታ',
    filterByOrigin: 'የምዝገባ ደረጃ',

    // Empty states
    noGrievances: 'ምንም ቅሬታ አልተገኘም',
    noMyGrievances: 'እስካሁን ምንም ቅሬታ አላቀረቡም',
    noCommittees: 'ምንም ኮሚቴ አልተገኘም',

    // Origin level values
    originLevelWoreda: 'ወረዳ',
    originLevelPool: 'ፑል',
    originLevelOrganization: 'ተቋም',
    originLevelOrganizationUnit: 'የተቋም ክፍል',

    // Committee type values
    committeeTypeGrievance: 'የቅሬታ ኮሚቴ',
    committeeTypeDisciplinary: 'የዲሲፕሊን ኮሚቴ',
    committeeTypeTribunal: 'የፍርድ ቤት ኮሚቴ',

    // Member role values
    memberRoleChairperson: 'ሰብሳቢ',
    memberRoleSecretary: 'ጸሐፊ',
    memberRoleMember: 'አባል',

    // Tribunal case status values
    tribunalStatusOpen: 'ክፍት',
    tribunalStatusHearing: 'ሰሚ',
    tribunalStatusDecided: 'ውሳኔ ተሰጥቷል',
    tribunalStatusClosed: 'ተዘግቷል',

    // Escalation reason values
    escalationReasonSlaBreach: 'የ SLA ጊዜ ማለፍ',
    escalationReasonManual: 'በእጅ ማሳለፍ',
    escalationReasonManagerOverride: 'በሥልጣን ማሳለፍ',

    // Flash messages
    categoryCreated: 'ምድቡ ተፈጥሯል',
    categoryUpdated: 'ምድቡ ተዘምኗል',
} as const;

export default grievances;
