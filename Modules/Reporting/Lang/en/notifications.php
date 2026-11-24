<?php

declare(strict_types=1);

/* Notices in the bell and by e-mail. Placeholders: :institution, :type, :period, :due, :days, :reference, :title. */
return [
    'action' => 'Open in Reporting',

    'submission_before_subject' => 'Report due in :days day(s): :type',
    'submission_before_body' => ':type for :period (:institution) is due by :due.',
    'submission_due_subject' => 'Report due today: :type',
    'submission_due_body' => ':type for :period (:institution) is due by :due.',
    'submission_overdue_subject' => 'Report overdue: :type',
    'submission_overdue_body' => ':type for :period (:institution) was due by :due and has not been submitted.',
    'submission_escalated_subject' => 'Escalation: report still not submitted — :type',
    'submission_escalated_body' => ':type for :period (:institution) was due by :due and is still not submitted.',

    'revision_before_subject' => 'Revision due in :days day(s): :type',
    'revision_before_body' => 'The returned :type for :period (:institution) is to be resubmitted by :due.',
    'revision_due_subject' => 'Revision due today: :type',
    'revision_due_body' => 'The returned :type for :period (:institution) is to be resubmitted by :due.',
    'revision_overdue_subject' => 'Revision overdue: :type',
    'revision_overdue_body' => 'The returned :type for :period (:institution) was to be resubmitted by :due.',
    'revision_escalated_subject' => 'Escalation: revision still not resubmitted — :type',
    'revision_escalated_body' => 'The returned :type for :period (:institution) was to be resubmitted by :due.',

    'review_before_subject' => 'Review due in :days day(s): :institution',
    'review_before_body' => 'The review of :type for :period from :institution is due by :due.',
    'review_due_subject' => 'Review due today: :institution',
    'review_due_body' => 'The review of :type for :period from :institution is due by :due.',
    'review_overdue_subject' => 'Review overdue: :institution',
    'review_overdue_body' => 'The review of :type for :period from :institution was due by :due.',
    'review_escalated_subject' => 'Escalation: review still open — :institution',
    'review_escalated_body' => 'The review of :type for :period from :institution was due by :due and is still open.',

    'workflow_submitted_subject' => 'Report received: :institution',
    'workflow_submitted_body' => ':institution submitted :type for :period (:reference). It awaits review.',
    'workflow_returned_subject' => 'Report returned for revision: :type',
    'workflow_returned_body' => ':type for :period (:reference) was returned. Read the reviewer\'s comments, revise and resubmit.',
    'workflow_approved_subject' => 'Report approved: :type',
    'workflow_approved_body' => ':type for :period (:reference) was approved.',
    'workflow_rejected_subject' => 'Report rejected: :type',
    'workflow_rejected_body' => ':type for :period (:reference) was rejected. The reason is on the report\'s page.',
    'workflow_follow_up_assigned_subject' => 'Follow-up action assigned to you',
    'workflow_follow_up_assigned_body' => 'A follow-up action was assigned to you: :title.',
];
