<?php

declare(strict_types=1);

/*
 * Support help desk — server-side strings (docs/assistant/support-helpdesk.md):
 * flash messages and the labels of a ticket's category, priority, status and
 * default team, for notifications and exports. The pages themselves translate
 * through resources/js/i18n/{en,am}/support.ts.
 */
return [
    'title' => 'Support tickets',
    // The team a ticket lands with when no category routing is configured (config/support.php).
    'default_team' => 'Service Desk',

    'flash' => [
        'created' => 'Ticket :reference created. The support team will follow up.',
        'updated' => 'Ticket updated.',
    ],

    'categories' => [
        'access' => 'Access or permission',
        'error' => 'Error message',
        'data' => 'Wrong or missing data',
        'how_to' => 'How do I…',
        'performance' => 'Slow or unresponsive',
        'other' => 'Other',
    ],

    'priorities' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
    ],

    'statuses' => [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ],

    'events' => [
        'created' => 'Ticket created',
        'status_changed' => 'Status changed',
        'assigned' => 'Assigned',
        'note' => 'Note',
    ],

    'team' => [
        'default' => 'Service Desk',
    ],
];
