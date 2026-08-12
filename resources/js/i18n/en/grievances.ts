const grievances = {
    // Module labels
    grievances: 'Grievances',
    grievance: 'Grievance',
    myGrievances: 'My Grievances',
    committees: 'Committees',
    committee: 'Committee',
    slaRules: 'SLA Rules',
    slaRule: 'SLA Rule',
    tribunalCases: 'Tribunal Cases',
    tribunalCase: 'Tribunal Case',
    category: 'Category',

    // Fields
    referenceNumber: 'Reference Number',
    subject: 'Subject',
    description: 'Description',
    organization: 'Organization',
    organizationUnit: 'Organization Unit',
    originLevel: 'Origin Level',
    status: 'Status',
    submittedAt: 'Submitted At',
    submittedBy: 'Submitted By',
    members: 'Members',
    activeMembersCount: 'Active Members',
    memberRole: 'Role',
    committeeType: 'Committee Type',
    effectiveFrom: 'Effective From',
    effectiveTo: 'Effective To',
    escalationFromType: 'Escalated From',
    escalationToType: 'Escalated To',
    workingDaysLimit: 'Working Days Limit',
    escalations: 'Escalations',
    escalationReason: 'Reason',
    revisionRound: 'Round',
    response: 'Response',
    responseBodyEn: 'Response Body (English)',
    responseBodyAm: 'Response Body (Amharic)',
    rejectionReason: 'Rejection Reason',
    requirementNotes: 'Requirement Notes',
    caseNumber: 'Case Number',
    hearingDate: 'Hearing Date',
    decisionDate: 'Decision Date',
    decisionSummary: 'Decision Summary',
    decisionLetter: 'Decision Letter',
    letterReference: 'Letter Reference',
    generatedAt: 'Generated At',

    // Actions
    submitGrievance: 'Submit Grievance',
    assignCommittee: 'Assign Committee',
    compileResponse: 'Compile Response',
    approveResponse: 'Approve Response',
    rejectResponse: 'Reject Response',
    markFulfilled: 'Mark Fulfilled',
    markIncomplete: 'Mark Incomplete',
    checkRequirements: 'Check Requirements',
    addMember: 'Add Member',
    removeMember: 'Remove',
    downloadLetter: 'Download Letter',

    // Filters
    filterByOrganization: 'Organization',
    filterByStatus: 'Status',
    filterByOrigin: 'Origin Level',

    // Empty states
    noGrievances: 'No grievances found',
    noMyGrievances: 'You have not submitted any grievances yet',
    noCommittees: 'No committees found',

    // Origin level values
    originLevelWoreda: 'Woreda',
    originLevelPool: 'Pool',
    originLevelOrganization: 'Organization',
    originLevelOrganizationUnit: 'Org. Unit',

    // Committee type values
    committeeTypeGrievance: 'Grievance Committee',
    committeeTypeDisciplinary: 'Disciplinary Committee',
    committeeTypeTribunal: 'Tribunal Committee',

    // Member role values
    memberRoleChairperson: 'Chairperson',
    memberRoleSecretary: 'Secretary',
    memberRoleMember: 'Member',

    // Tribunal case status values
    tribunalStatusOpen: 'Open',
    tribunalStatusHearing: 'Hearing',
    tribunalStatusDecided: 'Decided',
    tribunalStatusClosed: 'Closed',

    // Escalation reason values
    escalationReasonSlaBreach: 'SLA Breach',
    escalationReasonManual: 'Manual Escalation',
    escalationReasonManagerOverride: 'Manager Override',

    // Flash messages (used in Laravel lang files — kept here for reference)
    categoryCreated: 'Category created',
    categoryUpdated: 'Category updated',
} as const;

export default grievances;
