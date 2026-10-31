<?php

return [
    // The criterion worked out from the appellate court's outcomes (EUISIS ADR 0013 #14).
    'appeal_outcomes' => [
        'comment' => ':total appeal(s) decided: :confirmed confirmed, :modified modified, :reversed reversed.',
        'automatic' => 'Worked out from the record',
        'counting' => 'Decisions of this member\'s panels that the appellate court decided in the period.',
        'result' => ':total appeal(s): :confirmed confirmed, :modified modified, :reversed reversed. Score :score.',
        'none' => 'No appeal was decided in the period: this criterion is left out of the overall score.',
        'pick' => 'Choose the member and the period to see it.',
    ],
    'title' => 'Performance Evaluations',
    'detail_title' => 'Evaluation Detail',
    'new_title' => 'New Performance Evaluation',
    'edit_title' => 'Edit Evaluation',
    'new_evaluation' => 'New Evaluation',
    'edit_evaluation' => 'Edit Evaluation',
    'subtitle' => 'Score each criterion from 0 (poor) to 10 (excellent)',
    'details' => 'Evaluation Details',
    'criteria_scores' => 'Criteria Scores',
    'criteria_breakdown' => 'Criteria Breakdown',
    'score_hint' => 'Score: 0 = Poor · 5 = Average · 10 = Excellent',
    'score_hint_short' => '0 = Poor · 5 = Average · 10 = Excellent',
    'not_available' => 'N/A',

    'stats' => [
        'total' => 'Total',
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'reviewed' => 'Reviewed',
        'avg_score' => 'Avg Score',
    ],

    'filters' => [
        'all_statuses' => 'All statuses',
        'all_members' => 'All members',
        'all_periods' => 'All periods',
        'clear' => 'Clear',
    ],

    'tabs' => [
        'all' => 'All',
    ],

    'pagination' => [
        'showing' => 'Showing :from to :to of :total evaluations',
    ],

    'fields' => [
        'member' => 'Member',
        'staff_member' => 'Staff Member',
        'period' => 'Period',
        'period_type' => 'Period Type',
        'period_start' => 'Period Start',
        'period_end' => 'Period End',
        'type' => 'Type',
        'score' => 'Score',
        'status' => 'Status',
        'evaluator' => 'Evaluator',
        'date' => 'Date',
        'created' => 'Created',
        'reviewed_by' => 'Reviewed by',
        'reviewed_at' => 'Reviewed at',
        'notes' => 'Notes',
        'reviewer_notes' => 'Reviewer Notes',
        'general_notes' => 'General Notes',
        'weight' => 'Weight',
        'actions' => 'Actions',
    ],

    'placeholders' => [
        'select_member' => 'Select member...',
        'general_notes' => 'Optional overall comments...',
        'criterion_comment' => 'Optional comment for this criterion...',
        'comment' => 'Optional comment...',
        'reviewer_notes' => 'Add reviewer notes (optional)...',
    ],

    'actions' => [
        'new' => 'New Evaluation',
        'view' => 'View',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'cancel' => 'Cancel',
        'back' => 'Back',
        'back_to_list' => 'Back to list',
        'save_draft' => 'Save as Draft',
        'submit_review' => 'Submit for Review',
        'approve_reviewed' => 'Approve & Mark as Reviewed',
        'create_first' => 'Create the first one',
    ],

    'empty' => [
        'evaluations' => 'No evaluations found',
    ],

    'statuses' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'reviewed' => 'Reviewed',
    ],

    'periods' => [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'annual' => 'Annual',
    ],

    'score_labels' => [
        'excellent' => 'Excellent',
        'good' => 'Good',
        'satisfactory' => 'Satisfactory',
        'needs_improvement' => 'Needs Improvement',
        'poor' => 'Poor',
        'average' => 'Average',
    ],

    'categories' => [
        'general' => 'General',
        'efficiency' => 'Efficiency',
        'quality' => 'Quality',
        'conduct' => 'Conduct',
    ],

    'review' => [
        'title' => 'Review this Evaluation',
    ],

    'confirm' => [
        'delete' => 'Delete this evaluation?',
    ],

    'settings' => [
        'title' => 'Performance Evaluation Setting',
        'subtitle' => 'Manage the criteria, scoring weights, order, and active status used when creating evaluations.',
        'create_criterion_title' => 'Add Criterion',
        'create_criterion_subtitle' => 'Create one scoring criterion for future performance evaluations.',
        'create_category_title' => 'Add Category',
        'create_category_subtitle' => 'Create a reusable category for grouping evaluation criteria.',
        'view_criterion_title' => 'Criterion Details',
        'edit_criterion_title' => 'Edit Criterion',
        'back_to_evaluations' => 'Back to evaluations',
        'criteria_title' => 'Criteria and Scores',
        'criteria_hint' => 'Active criteria appear on new evaluation forms. Weights are used to calculate the overall score.',
        'active_weight' => 'Active weight',
        'used_scores' => 'Used scores',
        'add_title' => 'Add Criterion',
        'add_hint' => 'Create a new measurable criterion for future evaluations.',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'slug_hint' => 'Leave blank to generate it from the name. Use letters, numbers, dashes, or underscores.',
        'empty' => 'No performance criteria found.',
        'confirm_delete' => 'Delete this criterion? If it already has evaluation scores, it will be deactivated instead.',
        'fields' => [
            'name' => 'Name',
            'name_am' => 'Amharic name',
            'slug' => 'Slug',
            'category' => 'Category',
            'weight' => 'Weight',
            'order' => 'Order',
            'status' => 'Status',
            'description' => 'Description',
        ],
        'actions' => [
            'add' => 'Add Criterion',
            'add_criterion' => 'Add Criterion',
            'add_category' => 'Add Category',
            'update' => 'Update',
            'delete' => 'Delete',
        ],
        'messages' => [
            'created' => 'Performance criterion created.',
            'category_created' => 'Performance category created.',
            'category_slug_exists' => 'A performance category with this slug already exists.',
            'updated' => 'Performance criterion updated.',
            'deleted' => 'Performance criterion deleted.',
            'deactivated_in_use' => 'This criterion already has scores, so it was deactivated instead of deleted.',
        ],
        'validation' => [
            'attributes' => [
                'name' => 'name',
                'name_am' => 'Amharic name',
                'category' => 'category',
                'weight' => 'weight',
                'description' => 'description',
                'sort_order' => 'order',
                'active' => 'active status',
            ],
        ],
        'category_validation' => [
            'attributes' => [
                'name' => 'name',
                'name_am' => 'Amharic name',
                'slug' => 'slug',
                'sort_order' => 'order',
                'active' => 'active status',
            ],
        ],
    ],

    'messages' => [
        'submitted' => 'Evaluation submitted successfully.',
        'draft' => 'Evaluation saved as draft.',
        'updated' => 'Evaluation updated successfully.',
        'deleted' => 'Evaluation deleted.',
        'reviewed' => 'Evaluation reviewed and approved.',
        'reviewed_cannot_edit' => 'Reviewed evaluations cannot be edited.',
        'reviewed_cannot_delete' => 'Reviewed evaluations cannot be deleted.',
        'submitted_only_review' => 'Only submitted evaluations can be reviewed.',
    ],

    'validation' => [
        'attributes' => [
            'evaluated_user_id' => 'staff member',
            'period_type' => 'period type',
            'period_start' => 'period start',
            'period_end' => 'period end',
            'notes' => 'general notes',
            'scores' => 'scores',
            'scores.*.criterion_id' => 'criterion',
            'scores.*.score' => 'score',
            'scores.*.comment' => 'criterion comment',
            'action' => 'action',
            'reviewer_notes' => 'reviewer notes',
        ],
    ],
];
