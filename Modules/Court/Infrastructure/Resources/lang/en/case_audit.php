<?php

// The case page's audit trail (Admin\CaseController::show, Pages/Court/Cases/Show/AuditSection.tsx).
return [
    'actions' => [
        'applicant_updated' => 'Applicant updated the case',
        'review_decision' => 'Review decision',
        'assigned' => 'Assigned',
        'unassigned' => 'Assignment removed',
        'message_posted' => 'Message sent',
        'status_updated' => 'Status changed',
        'file_uploaded' => 'File uploaded',
        'hearing_created' => 'Hearing scheduled',
        'hearing_updated' => 'Hearing changed',
        'hearing_deleted' => 'Hearing removed',
        'respondent_viewed' => 'Respondent opened the case',
        'response_reply_reviewed' => 'Reply reviewed',
    ],
    'actors' => [
        'user' => 'Court staff',
        'applicant' => 'Applicant',
        'respondent' => 'Respondent',
        'system' => 'System',
    ],
    'fields' => [
        'respondent' => 'Respondent',
        'decision' => 'Decision',
        'note' => 'Note',
        'from' => 'From',
        'to' => 'To',
        'team' => 'Bench',
        'judge' => 'Assigned to',
        'member' => 'Bench member',
        'by' => 'By',
        'body' => 'Message',
        'label' => 'Document',
        'when' => 'Hearing',
        'hearing' => 'Hearing no.',
        'sections' => 'Changed',
    ],
    'by' => [
        'admin' => 'the court',
        'applicant' => 'the applicant',
    ],
];
