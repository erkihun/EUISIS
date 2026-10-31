<?php

return [
    'anonymous' => 'Anonymous visitor', 'saved' => 'Reception record saved.',
    'visit_registered' => 'Visitor checked in. Reference: :reference', 'ticket_issued' => 'Queue ticket :number issued.', 'queue_updated' => 'Queue status updated.',
    'queue_slip' => 'Queue slip', 'appointment_confirmation' => 'Appointment confirmation', 'referral_slip' => 'Referral slip',
    'desk' => 'Reception desk', 'reference' => 'Reference', 'visitor' => 'Visitor', 'issued_at' => 'Issued at', 'scheduled_at' => 'Scheduled at', 'reason' => 'Reason', 'referred_at' => 'Referred at', 'print' => 'Print',
    'validation' => [
        'desk_organization' => 'The selected reception desk does not belong to this institution.',
        'active_ticket' => 'This visit already has a queue ticket.',
        'invalid_transition' => 'That queue transition is not allowed from the current status.',
        'non_working_day' => 'Appointments must be scheduled on a configured working day that is not a public holiday.',
        'appointment_scope' => 'The selected appointment is not active for this reception desk.',
        'counter_scope' => 'The selected counter does not belong to this reception desk.',
        'visitor_scope' => 'The selected visitor is not available to this institution.',
        'unit_scope' => 'The selected unit does not belong to the destination institution.',
        'service_scope' => 'The selected service does not belong to the destination institution.',
    ],
];
