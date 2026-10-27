<?php

declare(strict_types=1);

/*
 * Executive Situation Room — server-side messages.
 * Mirrored in lang/am/situation-room.php.
 */
return [
    'owner_out_of_scope' => 'The chosen owner cannot work on this institution.',
    'unit_out_of_scope' => 'The chosen unit does not belong to this institution.',
    'action_created' => 'Action recorded.',
    'action_updated' => 'Action updated.',
    'export_queued' => 'The export is being prepared. It will appear in the list when ready.',
    'export_not_ready' => 'This export is not ready yet.',
    'refreshed' => 'Figures refreshed.',
    'recorded_at_itself' => ':name (recorded at the institution itself)',
    'recorded_elsewhere' => 'Recorded at institutions outside the published hierarchy',
    'export_columns' => [
        'code' => 'Code',
        'institution' => 'Institution',
        'total' => 'Total',
        'feedback' => 'Feedback',
        'suggestion' => 'Suggestions',
        'complaint' => 'Complaints',
        'unresolved' => 'Unresolved',
        'resolved' => 'Resolved',
        'unrouted' => 'Unrouted',
        'unresolved_complaints' => 'Unresolved complaints',
    ],
];
