/* grievanceWork — decisions, approvals, appeals, correspondence register and reports. */
const grievanceWork = {
    // Decisions
    decision: 'Decision', decision_type: 'Decision type', new_decision: 'Draft a decision', save_decision: 'Save decision',
    findings: 'Findings', facts_considered: 'Facts considered', legal_basis: 'Legal basis', analysis: 'Analysis', decision_text: 'Decision',
    recommendations: 'Recommendations', corrective_action_required: 'Corrective action required', disciplinary_referral_recommended: 'Disciplinary referral recommended',
    transition: 'Next step', submit_for_review: 'Send for internal review', endorse: 'Endorse', return_internal: 'Return to preparer',
    submit_for_approval: 'Send for executive approval', finalize: 'Finalize', revise: 'Create a corrected version', vote: 'Vote', opinion: 'Opinion / dissent',

    // Approvals
    approvals: 'Executive approvals', pending: 'Pending', history: 'History', review_case: 'Open case', record_approval: 'Record approval decision',
    approve: 'Approve', return_for_correction: 'Return for correction', reject: 'Reject', comment_required: 'A comment is required to return or reject.',

    // Appeals
    appeals: 'Appeals',

    // Correspondence register
    correspondence: 'Correspondence register',
    correspondence_help: 'Official grievance letters you may see. Registry officers seal, issue and dispatch signed letters here.',
    search_reference: 'Reference or case number', no_letters: 'No letters.', sealed: 'Sealed', apply_seal: 'Apply seal', seal: 'Seal', issue: 'Issue',
    dispatch: 'Record dispatch', channel: 'Channel',
    col_reference: 'Reference', col_case: 'Case', col_type: 'Type', col_subject: 'Subject', col_status: 'Status', col_signed: 'Signed by', col_issued: 'Issued', col_actions: 'Actions',

    // Reports
    reports: 'Grievance reports',
    reports_note: 'Aggregate, process-focused reports. They never rank or list employees who filed grievances.',
    no_rows: 'No data for these filters.', suppressed: ':count group(s) with fewer than :min cases are hidden for privacy.',
    export_csv: 'Export CSV', export_xlsx: 'Export Excel', export_pdf: 'Export PDF',
    report_summary: 'Summary', report_by_organization: 'By organization', report_by_category: 'By category', report_by_handler: 'By handler',
    report_by_status: 'By status', report_overdue: 'Overdue', report_escalations: 'Escalations', report_appeals: 'Appeals', report_approvals: 'Executive approvals',
    report_resolution_time: 'Resolution time', report_sla_compliance: 'SLA compliance', report_correspondence: 'Correspondence', report_trend: 'Monthly trend',
    col_submitted: 'Submitted', col_open: 'Open', col_decision_issued: 'Decision issued', col_closed: 'Closed', col_withdrawn: 'Withdrawn',
    col_rejected_at_intake: 'Rejected at intake', col_returned_for_correction: 'Returned for correction', col_auto_escalated: 'Auto-escalated',
    col_appealed: 'Appealed', col_overdue_now: 'Overdue now', col_name_en: 'Name', col_total: 'Total', col_resolved: 'Resolved',
    col_escalated_out: 'Escalated out', col_overdue: 'Overdue', col_key: 'Status', col_reference_number: 'Case number', col_handler: 'Handler',
    col_stage_no: 'Stage', col_due_at: 'Due', col_days_overdue: 'Days overdue', col_movement_type: 'Movement', col_target: 'To', col_pending: 'Pending',
    col_pending_overdue: 'Pending past due', col_approved: 'Approved', col_returned: 'Returned', col_rejected: 'Rejected', col_resolved_cases: 'Resolved cases',
    col_average_days: 'Average days', col_median_days: 'Median days', col_max_days: 'Longest (days)', col_handler_type: 'Handler type', col_stages: 'Stages',
    col_on_time: 'On time', col_late_or_escalated: 'Late or escalated', col_compliance_percent: 'Compliance %', col_letter_type: 'Letter type',
    col_month: 'Month', col_category: 'Category', col_systemic_flagged: 'Flagged systemic',
};

export default grievanceWork;
