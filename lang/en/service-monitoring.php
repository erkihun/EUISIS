<?php

declare(strict_types=1);

return [
    'flash' => [
        'form_saved' => 'The form was saved.',
        'form_published' => 'The form was published. Institutions of the assigned types can now report on it.',
        'form_version_created' => 'A new draft version was created.',
        'form_duplicated' => 'The form was copied into a new draft.',
        'form_archived' => 'The form was archived.',
        'form_discarded' => 'The draft was discarded.',
        'period_saved' => 'The reporting period was saved.',
        'report_saved' => 'The report was saved as a draft.',
        'report_submitted' => 'The report was submitted.',
        'report_accepted' => 'The report was accepted.',
        'report_returned' => 'The report was returned to the institution for correction.',
    ],

    'errors' => [
        'form_not_editable' => 'A published form cannot be changed. Create a new version instead.',
        'only_draft_publishable' => 'Only a draft can be published.',
        'no_organization_types' => 'Assign the form to at least one organization type before publishing.',
        'draft_exists' => 'This form already has a draft. Edit or publish it first.',
        'draft_not_archivable' => 'A draft is discarded, not archived.',
        'draft_in_use' => 'Only an unused draft can be discarded.',
        'form_not_published' => 'Reports are started only on a published form.',
        'period_closed' => 'This reporting period is closed.',
        'form_not_assigned' => 'This form is not assigned to the institution\'s organization type.',
        'organization_out_of_scope' => 'The institution is outside your organization scope.',
        'report_locked' => 'This report has been submitted and can no longer be changed.',
        'only_submitted_reviewable' => 'Only a submitted report can be reviewed.',
        'own_report' => 'You cannot review a report you submitted.',
        'period_exists' => 'This period already exists for the fiscal year.',
    ],
];
