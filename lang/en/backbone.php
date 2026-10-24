<?php

declare(strict_types=1);

return [
    // Master backbone commands (docs/architecture/master-backbone.md).
    'transfer_effective_date_after_current' => 'The effective date must be after :date, the day the current assignment started.',
    // Ending and restarting a placement when employment ends or resumes (ADR 0002).
    'assignment_end_before_start' => 'The placement cannot end before :date, the day it started.',
    'assignment_no_previous_placement' => 'This employee has no previous placement to return to. Place them through a transfer.',
    'assignment_position_inactive' => 'The employee\'s last position is no longer active. Place them through a transfer.',
    'assignment_position_full' => 'The employee\'s last position has no free approved seat. Place them through a transfer.',
    // The master-data freeze used during a cutover or restore window (config/core.php).
    'master_data_frozen' => 'Master data is read-only during planned maintenance. Your change was not saved; try again after the maintenance window.',
];
