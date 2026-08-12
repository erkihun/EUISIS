<?php

declare(strict_types=1);

return [
    // Module title
    'module' => 'Grievance Management',
    'grievances' => 'Grievances',
    'grievance' => 'Grievance',
    'myGrievances' => 'My Grievances',

    // Fields
    'referenceNumber' => 'Reference Number',
    'subject' => 'Subject',
    'description' => 'Description',
    'category' => 'Category',
    'originLevel' => 'Origin Level',
    'organization' => 'Organization',
    'organizationUnit' => 'Organization Unit',
    'submittedBy' => 'Submitted By',
    'submittedAt' => 'Submitted At',
    'status' => 'Status',
    'closedAt' => 'Closed At',

    // Origin levels
    'originLevelWoreda' => 'Woreda',
    'originLevelPool' => 'Pool',
    'originLevelOrganization' => 'Organization',
    'originLevelOrganizationUnit' => 'Organization Unit',

    // Status labels
    'statusDraft' => 'Draft',
    'statusSubmitted' => 'Submitted',
    'statusUnderReview' => 'Under Review',
    'statusRequirementIncomplete' => 'Requirement Incomplete',
    'statusRequirementFulfilled' => 'Requirement Fulfilled',
    'statusInProgress' => 'In Progress',
    'statusResponseDrafted' => 'Response Drafted',
    'statusResponseCompiled' => 'Response Compiled',
    'statusAwaitingApproval' => 'Awaiting Approval',
    'statusApproved' => 'Approved',
    'statusRejected' => 'Rejected',
    'statusClosed' => 'Closed',
    'statusEscalated' => 'Escalated',
    'statusTribunalReferred' => 'Tribunal Referred',
    'statusWithdrawn' => 'Withdrawn',

    // Actions
    'submitGrievance' => 'Submit Grievance',
    'assignCommittee' => 'Assign Committee',
    'checkRequirements' => 'Check Requirements',
    'draftResponse' => 'Draft Response',
    'compileResponse' => 'Compile Response',
    'approveResponse' => 'Approve Response',
    'rejectResponse' => 'Reject Response',
    'downloadLetter' => 'Download Decision Letter',
    'viewLetter' => 'View Letter',

    // Committee
    'committees' => 'Committees',
    'committee' => 'Committee',
    'committeeType' => 'Committee Type',
    'committeeTypeGrievance' => 'Grievance Committee',
    'committeeTypeDisciplinary' => 'Disciplinary Committee',
    'committeeTypeTribunal' => 'Administrative Tribunal',
    'members' => 'Members',
    'member' => 'Member',
    'memberRole' => 'Role',
    'memberRoleChairperson' => 'Chairperson',
    'memberRoleSecretary' => 'Secretary',
    'memberRoleMember' => 'Member',
    'effectiveFrom' => 'Effective From',
    'effectiveTo' => 'Effective To',
    'addMember' => 'Add Member',
    'removeMember' => 'Remove Member',
    'activeMembersCount' => 'Active Members',

    // Response
    'response' => 'Response',
    'responseBodyEn' => 'Response Body (English)',
    'responseBodyAm' => 'Response Body (Amharic)',
    'rejectionReason' => 'Rejection Reason',
    'revisionRound' => 'Revision Round',
    'compiledBy' => 'Compiled By',
    'approvedBy' => 'Approved By',
    'rejectedBy' => 'Rejected By',

    // Response statuses
    'responseStatusDraft' => 'Draft',
    'responseStatusCompiled' => 'Compiled',
    'responseStatusAwaitingManagerApproval' => 'Awaiting Manager Approval',
    'responseStatusApprovedByManager' => 'Approved by Manager',
    'responseStatusRejectedByManager' => 'Rejected by Manager',
    'responseStatusIssued' => 'Issued',

    // Decision letter
    'decisionLetter' => 'Decision Letter',
    'letterReference' => 'Letter Reference',
    'generatedAt' => 'Generated At',
    'downloadedAt' => 'Downloaded At',

    // SLA Rules
    'slaRules' => 'SLA Rules',
    'slaRule' => 'SLA Rule',
    'workingDaysLimit' => 'Working Days Limit',
    'escalationFromType' => 'Escalation From',
    'escalationToType' => 'Escalation To',

    // Escalation
    'escalations' => 'Escalation History',
    'escalatedAt' => 'Escalated At',
    'escalationReason' => 'Reason',
    'escalationReasonSlaBreach' => 'SLA Breach',
    'escalationReasonManual' => 'Manual',

    // Tribunal
    'tribunalCases' => 'Tribunal Cases',
    'tribunalCase' => 'Tribunal Case',
    'caseNumber' => 'Case Number',
    'hearingDate' => 'Hearing Date',
    'decisionDate' => 'Decision Date',
    'decisionSummary' => 'Decision Summary',
    'tribunalStatusOpen' => 'Open',
    'tribunalStatusHearing' => 'In Hearing',
    'tribunalStatusDecided' => 'Decided',
    'tribunalStatusClosed' => 'Closed',

    // Requirement check
    'requirementFulfilled' => 'Requirements Fulfilled',
    'requirementIncomplete' => 'Requirements Incomplete',
    'requirementNotes' => 'Notes',
    'requirementCheckedAt' => 'Checked At',
    'markFulfilled' => 'Mark as Fulfilled',
    'markIncomplete' => 'Mark as Incomplete',

    // Success messages
    'submitted' => 'Grievance submitted successfully.',
    'assigned' => 'Grievance assigned to committee.',
    'requirementChecked' => 'Requirement check recorded.',
    'responseCompiled' => 'Response compiled and submitted for approval.',
    'responseApproved' => 'Response approved. Decision letter has been generated.',
    'responseRejected' => 'Response rejected and returned for revision.',
    'committeeCreated' => 'Committee created successfully.',
    'committeeUpdated' => 'Committee updated.',
    'committeeDeleted' => 'Committee deleted.',
    'memberAdded' => 'Member added to committee.',
    'memberRemoved' => 'Member removed from committee.',
    'categoryCreated' => 'Category created.',
    'categoryUpdated' => 'Category updated.',
    'slaRuleCreated' => 'SLA rule created.',
    'slaRuleUpdated' => 'SLA rule updated.',
    'slaRuleDeleted' => 'SLA rule deleted.',
    'tribunalCaseUpdated' => 'Tribunal case updated.',
    'letterNotFound' => 'Decision letter not found.',

    // Validation errors
    'notAssigned' => 'Grievance is not currently assigned to a committee.',
    'noCompiledResponse' => 'No compiled response found to approve or reject.',
    'committeeMaxMembers' => 'A committee cannot have more than 5 active members.',
    'committeeMinMembers' => 'A committee must have at least 3 active members.',
    'committeeAlreadyHasChairperson' => 'This committee already has an active chairperson.',

    // Committee dashboard
    'committeeDashboard' => 'Committee Dashboard',
    'assignedGrievances' => 'Assigned Grievances',
    'overdueGrievances' => 'Overdue Grievances',
    'pendingApproval' => 'Pending Approval',

    // Empty states
    'noGrievances' => 'No grievances found.',
    'noMyGrievances' => 'You have not submitted any grievances.',
    'noCommittees' => 'No committees configured.',
    'noEscalations' => 'No escalations recorded.',

    // Filters
    'filterByStatus' => 'Filter by Status',
    'filterByOrigin' => 'Filter by Origin Level',
    'filterByOrganization' => 'Filter by Organization',
];
