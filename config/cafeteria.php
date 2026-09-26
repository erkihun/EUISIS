<?php

declare(strict_types=1);

return [
    /*
     * Maker-checker for financial policy: the person approving a cafeteria
     * service policy must not be the one who drafted or submitted it.
     * Turn off only for single-administrator installations.
     */
    'policy_requires_distinct_approver' => (bool) env('CAFETERIA_POLICY_DISTINCT_APPROVER', true),

    // Days ahead the dashboard warns about binding policies that end.
    'policy_expiry_warning_days' => (int) env('CAFETERIA_POLICY_EXPIRY_WARNING_DAYS', 30),
];
