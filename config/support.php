<?php

declare(strict_types=1);

/*
 * Support help desk (docs/assistant/support-helpdesk.md).
 *
 * NEEDS_POLICY_DECISION_SUPPORT_ROUTING: which team owns which ticket
 * category has not been decided. Until it is, every category routes to the
 * default team below; override per category with SUPPORT_TEAM_* or by editing
 * `routing`. Team names are free text shown on the ticket, not accounts.
 */
return [
    'default_team' => env('SUPPORT_DEFAULT_TEAM', 'Service Desk'),

    /* category => team name. A category missing here takes `default_team`. */
    'routing' => [
        'access' => env('SUPPORT_TEAM_ACCESS', 'Service Desk'),
        'error' => env('SUPPORT_TEAM_ERROR', 'Service Desk'),
        'data' => env('SUPPORT_TEAM_DATA', 'Service Desk'),
        'how_to' => env('SUPPORT_TEAM_HOW_TO', 'Service Desk'),
        'performance' => env('SUPPORT_TEAM_PERFORMANCE', 'Service Desk'),
        'other' => env('SUPPORT_TEAM_OTHER', 'Service Desk'),
    ],
];
