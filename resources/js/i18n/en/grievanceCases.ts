/* grievanceCases — staff case lists, dashboard and case detail. */
const grievanceCases = {
    dashboard: 'Grievance dashboard',
    assigned: 'Assigned cases',
    authorized: 'All authorized cases',
    intake: 'Intake review',
    queue: 'My queue (earliest deadline first)',
    empty: 'Nothing to show.',
    metrics: {
        total: 'Assigned to me', new: 'New (not yet received)', due_soon: 'Due soon', overdue: 'Overdue', awaiting_hearing: 'Awaiting hearing',
        awaiting_decision: 'Awaiting decision', returned: 'Returned decisions', escalated_in: 'Escalated or appealed in', pending_approval: 'Pending executive approval',
    },
    dash: {
        subtitle: 'Cases handled by you or your body, with the nearest deadlines first.',
        review_intake: 'Review intake', open_list: 'Open list', review: 'Review', act_now: 'Act now',
        total_hint: 'Open cases at your stage', new_hint: 'Acknowledge receipt to start handling',
        due_soon_hint: 'Including cases due today', overdue_hint: 'Past the stage deadline', nothing_overdue: 'Nothing is overdue',
        where: 'Where my cases are', open_count: ':n open',
        where_note: 'Counts of your open cases. A case that arrived by escalation or appeal is also counted under its stage.',
        attention: 'Needs attention', overdue_list: 'Overdue list', all_clear: 'No assigned case is overdue or due soon.',
        attention_count: ':n due soon or overdue', open_cases: 'Open my cases',
        queue: 'My queue', queue_help: 'Earliest deadline first · next 10 cases', view_all: 'View all cases',
        work_areas: 'Other work areas', intake_hint: ':n waiting for an intake decision', approvals_hint: ':n decisions waiting for you',
        correspondence_hint: 'Official letters, seals and dispatch', committees_hint: 'Membership and case panels', reports_hint: 'Aggregate, process-focused reports',
    },

    tabs: 'Case sections',
    tab_overview: 'Overview', tab_timeline: 'Timeline', tab_stages: 'Stages', tab_evidence: 'Evidence', tab_information: 'Information requests',
    tab_hearings: 'Hearings', tab_minutes: 'Minutes', tab_decision: 'Decision', tab_letters: 'Correspondence', tab_appeals: 'Appeal',
    tab_notes: 'Notes & tasks', tab_outcomes: 'Outcomes', tab_audit: 'Audit',
    workflow: 'Workflow actions', no_actions: 'No actions are available to you on this case right now.',
    your_role: 'Your role on this case',
    oversight_notice: 'You are viewing this case for oversight. It is read-only for you and your access is logged.',
    recusal_notice: 'You have declared a conflict of interest on this stage. You cannot act on the case.',

    // Intake and stage work
    intake_decision: 'Intake decision', intake_accept: 'Accept and route', intake_return: 'Return for correction', intake_reject: 'Reject at intake',
    intake_submit: 'Record intake decision', intake_reason_help: 'Required when returning or rejecting.', intake_notes: 'Intake notes',
    receive: 'Acknowledge receipt', receive_help: 'Confirms that your body has received the case.',
    start_review: 'Start review', start_review_help: 'Formally accepts the case for review at this stage.',
    withdrawal: 'Withdrawal', approve_withdrawal: 'Approve the withdrawal (leave unticked to refuse it)', withdrawal_help: 'The complainant asked to withdraw after review had started.',
    move: 'Escalate, reassign, refer or return', target: 'Configured route', move_submit: 'Move the case',
    move_help: 'Only configured, approved routes are offered. The move is recorded with its reason.',
    assign_officer: 'Assign a case officer', officer: 'Officer', officers: 'Case officers', release: 'Release', released: 'released',
    request_pause: 'Pause the deadline', pause_help: 'Needs approval unless you hold the pause permission. Every pause is audited.',
    declare_recusal: 'Declare a conflict of interest', recusal_help: 'Once approved you are removed from this case: no view, vote, signature or minutes.',
    classify: 'Classification', classify_help: 'Tags describe the process; they never imply guilt.',
    respondent: 'Concerns', respondent_type: 'Concerns (type)', root_cause: 'Root-cause category', systemic_issue: 'Systemic issue', corrective_required: 'Corrective action required',
    close_case: 'Close the case', reopen: 'Reopen', retention: 'Retention and legal hold',
    set_hold: 'Place legal hold', lift_hold: 'Lift legal hold', archive: 'Archive', archive_help: 'Only closed cases past their retention date without a legal hold can be archived.',

    // Overview
    accepted_at: 'Accepted', resolved_at: 'Resolved', closed_at: 'Closed', reason_codes: 'Reason codes', legal_hold: 'Legal hold',
    retention_until: 'Retain until', reopened: 'Times reopened', amendments: 'Corrections after return', changes: 'Changes',

    // Stages
    current_stage: 'Current stage', movement: 'How it arrived', paused_days: 'Paused days', panel: 'Committee panel for this case',
    replacement: 'Replacement', recused: 'recused', left: 'left the committee', pauses: 'Deadline pauses', stage_history: 'Handling history',
    pause_reason: 'Reason', pause_status: 'Status', pause_requested: 'Requested', pause_period: 'Period', pause_due_change: 'Due date before → after', pause_actions: 'Actions',
    approve: 'Approve', reject: 'Reject', resume: 'Resume',
    col_stage: 'Stage', col_handler: 'Handler', col_movement: 'Movement', col_status: 'Status', col_received: 'Received', col_due: 'Due', col_completed: 'Completed',
    recusals: 'Conflicts of interest', recusal_member: 'Member', recusal_reason: 'Reason', recusal_status: 'Status', recusal_decision: 'Decision', recusal_actions: 'Actions',
    find_replacement: 'Search replacement by name or number', approve_recusal: 'Approve the recusal', decide: 'Record decision',

    // Evidence
    ev_title: 'Title', ev_type: 'Type', ev_size: 'Size', ev_classification: 'Classification', ev_status: 'Status', ev_submitted: 'Submitted', ev_actions: 'Actions',
    by_complainant: 'Complainant', accept: 'Accept', more: 'More', new_version: 'Upload new version', custody: 'Chain of custody',
    upload_evidence: 'Add evidence', evidence_help: 'Files are stored privately and checked against their content type. Accepted evidence is never replaced; a new version is kept beside it.',

    // Information requests
    pauses_sla: 'Pauses the deadline', record_response: 'Record a response', response: 'Response', close_request: 'Close request', cancel_request: 'Cancel request',
    new_request: 'Request information', requested_from: 'Requested from', requested_from_name: 'Name of person or body', requested_from_name_help: 'Required for HR and other institutions.', request_text: 'What information is needed',

    // Hearings and minutes
    scheduled_at: 'Date and time', location: 'Location', duration: 'Duration (minutes)', chair: 'Chairperson', meeting_link: 'Meeting link', held_at: 'Held at',
    cancellation_reason: 'Cancellation reason', update_hearing: 'Update hearing', schedule_hearing: 'Schedule a hearing', mode: 'Mode', agenda: 'Agenda',
    hearing_help: 'The complainant and the active panel are invited automatically.',
    min_summary: 'Summary', min_discussion: 'Discussion', min_resolutions: 'Resolutions', attendees: 'Attendees', confirmed_by: 'confirmed by',
    confirm_minutes: 'Confirm minutes', confirm_minutes_help: 'Confirmed minutes can only be changed through an amendment.', amend_minutes: 'Amend (new version)', new_minutes: 'Record minutes',
    amendment_reason: 'Amendment reason',

    // Decision approval
    approver_actions: 'Executive approval', approver_help: 'Shown to the configured approver only; the server checks your authority again.',
    comment_required_help: 'Required when returning or rejecting.',

    // Correspondence
    generate_letter: 'Prepare a letter from a template', language: 'Language', no_reference: 'No reference number yet', signed_by: 'signed by', sealed: 'sealed',
    visible_to_complainant: 'Visible to the complainant', body: 'Letter text', void_reason: 'Voided because',
    finalize_letter: 'Finalize (assign reference number)', sign: 'Sign', signature_method: 'Signature method',
    signature_help: 'A signature image on a PDF is not a cryptographic digital signature.', apply_seal: 'Apply official seal', seal: 'Seal',
    issue: 'Issue', issue_help: 'Issuing freezes the letter; corrections need a void and a new version.', void: 'Void', revise_letter: 'Create corrected draft',
    dispatches: 'Dispatch record', acknowledge: 'Record acknowledgment', dispatch: 'Record dispatch', channel: 'Channel',
    dispatch_help: 'SMS and email carry a notice only, never the letter content. “Delivered” is recorded only when confirmed.',
    hash_help: 'Detects accidental replacement of the stored file. It is not a digital signature.',

    // Appeals
    ap_status: 'Status', ap_filed: 'Filed', ap_deadline: 'Deadline', ap_stages: 'From → to stage', ap_reason: 'Reason',

    // Notes, tasks, outcomes
    notes: 'Internal notes', notes_help: 'Never visible to the complainant.', note: 'Note', add_note: 'Add note',
    tasks: 'Tasks', add_task: 'Add task', task_title: 'Task', mark_done: 'Mark done',
    corrective_actions: 'Corrective actions', add_corrective: 'Add corrective action', referrals: 'Disciplinary referrals', add_referral: 'Refer to disciplinary process',
    referral_help: 'A referral is a separate, linked record. The grievance is never converted into a disciplinary case.',

    // Audit
    au_event: 'Event', au_actor: 'By', au_when: 'When', au_values: 'Recorded values', show_values: 'Show', system: 'System (scheduled)',
};

export default grievanceCases;
