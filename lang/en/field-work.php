<?php

declare(strict_types=1);

return [
    // Shown when no immediate supervisor resolves; the request waits, never auto-approved.
    'supervisor_not_resolved' => 'Submitted, but no immediate supervisor could be resolved (SUPERVISOR_NOT_RESOLVED). The request waits until HR configures the line-manager assignment for your unit; it is never approved automatically.',

    'status' => [
        'draft' => 'Draft',
        'pending_supervisor_approval' => 'Pending supervisor approval',
        'returned_for_correction' => 'Returned for correction',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'in_field' => 'In field',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    'errors' => [
        'no_employee' => 'Your account is not linked to an employee record.',
        'not_active' => 'Only active employees can request field work.',
        'no_assignment' => 'You have no current assignment, so field work cannot be requested.',
        'not_requester' => 'Only the employee who requested this field work can do that.',
        'not_participant' => 'You are not a participant of this field work.',
        'not_supervisor' => 'Only the immediate supervisor resolved for this request can decide it.',
        'invalid_transition' => 'This is not possible while the request is ":status".',
        'inactive_type' => 'Choose an active field work type.',
        'team_not_allowed' => 'You are not allowed to add team members.',
        'too_many_participants' => 'At most :max team members can be added.',
        'invalid_participant' => 'Team members must be active employees currently placed in your organization.',
        'return_before_start' => 'The expected return must be after the start.',
        'start_too_old' => 'The start cannot be more than 30 days in the past.',
        'too_long' => 'Field work cannot be longer than 90 days.',
        'unit_not_in_organization' => 'The unit does not belong to the chosen organization.',
        'conflict_field_work' => ':employee already has field work :reference in this period.',
        'conflict_leave' => ':employee is on approved leave in this period.',
        'already_started' => 'Field work that someone has checked in to cannot be cancelled.',
        'not_approved' => 'Check-in is possible only on approved field work.',
        'too_early' => 'It is too early to check in to this field work.',
        'window_closed' => 'The expected return has passed; check-in is closed.',
        'not_checked_in' => 'Check in before checking out.',
        'not_in_field' => 'This field work is not in progress.',
        'stale_capture' => 'The location reading is too old or from the future. Try again.',
        'check_in_required' => 'The GPS policy requires a check-in before this field work can be completed.',
        'check_out_required' => 'The GPS policy requires your GPS check-out before this field work can be completed.',
        'gps_blocked' => 'This location reading does not satisfy the configured GPS policy, so it was not recorded. Move to the destination or wait for better accuracy and try again.',
        'actual_return_range' => 'The actual return must be after the actual start and not in the future.',
        'reason_required' => 'A reason is required.',
    ],

    'flash' => [
        'saved' => 'Field work request saved as draft.',
        'submitted' => 'Field work request submitted to your supervisor.',
        'cancelled' => 'Field work request cancelled.',
        'approved' => 'Field work request approved.',
        'returned' => 'Field work request returned for correction.',
        'rejected' => 'Field work request rejected.',
        'checked_in' => 'GPS check-in recorded.',
        'already_checked_in' => 'You had already checked in; nothing was changed.',
        'checked_out' => 'GPS check-out recorded.',
        'already_checked_out' => 'You had already checked out; nothing was changed.',
        'completed' => 'Field work completed.',
        'type_saved' => 'Field work type saved.',
    ],

    'notifications' => [
        'action' => 'Open field work',
        'approval_required_subject' => 'Field work :reference needs your approval',
        'approval_required_body' => 'A field work request (:reference) from your team is waiting for your decision.',
        'approved_subject' => 'Field work :reference approved',
        'approved_body' => 'Your field work request :reference was approved.',
        'returned_subject' => 'Field work :reference returned for correction',
        'returned_body' => 'Your field work request :reference was returned for correction: :comment',
        'rejected_subject' => 'Field work :reference rejected',
        'rejected_body' => 'Your field work request :reference was rejected: :comment',
    ],
];
