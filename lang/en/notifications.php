<?php

// Core notification templates (ADR 0011). :number is the record's number or code; no personal details.
return [
    'events' => [
        'employee_submitted' => ['title' => 'Employee :number is waiting for verification', 'body' => 'Employee :number was submitted for verification. Open the record to check it.'],
        'employee_returned' => ['title' => 'Employee :number was returned', 'body' => 'Employee :number was returned for correction. Open the record to see why.'],
        'employee_verified' => ['title' => 'Employee :number is waiting for approval', 'body' => 'Employee :number was verified and now needs approval by someone else.'],
        'employee_approved' => ['title' => 'Employee :number was approved', 'body' => 'Employee :number was approved; the first placement is active.'],
        'hierarchy_submitted' => ['title' => 'Hierarchy :number is waiting for approval', 'body' => 'Hierarchy version :number was sent for review and approval.'],
        'hierarchy_approved' => ['title' => 'Hierarchy :number is ready to publish', 'body' => 'Hierarchy version :number was approved and can be published.'],
        'hierarchy_published' => ['title' => 'Hierarchy :number is in force', 'body' => 'Hierarchy version :number was published and is now the structure in force.'],
        'card_approved' => ['title' => 'Card request for :number approved', 'body' => 'The card request for employee :number was approved; the card is waiting to be printed.'],
        'card_printed' => ['title' => 'Card :number needs its quality check', 'body' => 'Card :number was printed. Someone other than its printer checks it before it is issued.'],
        'card_quality_failed' => ['title' => 'Card :number failed its quality check', 'body' => 'Card :number failed its quality check. Approve a reprint or revoke it.'],
        'card_ready_for_pickup' => ['title' => 'Card :number is ready', 'body' => 'Card :number passed its quality check and can be issued.'],
        'card_activated' => ['title' => 'Card :number is active', 'body' => 'Card :number was issued and activated.'],
        'card_expiring' => ['title' => 'Card :number expires soon', 'body' => 'Card :number expires on :until. Request a renewal in time.'],
        'card_suspended' => ['title' => 'Card :number was suspended', 'body' => 'Card :number was suspended and does not verify until it is reinstated.'],
        'card_revoked' => ['title' => 'Card :number was revoked', 'body' => 'Card :number was revoked; it and its credential no longer verify.'],
        'api_credential_rotated' => ['title' => 'API credential of :number replaced', 'body' => 'The secret of the connected application :number was replaced. The old secret no longer works.'],
        'api_client_revoked' => ['title' => 'API client :number revoked', 'body' => 'The connected application :number was revoked and can no longer call the Core.'],
        'webhook_suspended' => ['title' => 'Webhook :number suspended', 'body' => 'Webhook :number was suspended after repeated delivery failures. Its events wait until it is resumed.'],
        'break_glass_requested' => ['title' => 'Emergency access requested', 'body' => 'Someone asked for emergency access. Open the Security Center to approve or refuse it.'],
        'break_glass_approved' => ['title' => 'Your emergency access was approved', 'body' => 'Your emergency access is in force until it ends by itself. Everything you do under it is marked in the audit trail.'],
        'break_glass_expiring' => ['title' => 'Your emergency access ends soon', 'body' => 'Your emergency access ends at :until.'],
        'break_glass_expired' => ['title' => 'Your emergency access ended', 'body' => 'Your emergency access has ended.'],
        'import_completed' => ['title' => 'Import finished', 'body' => 'Your :number import finished. Open it to see the results.'],
        'import_failed' => ['title' => 'Import failed', 'body' => 'Your :number import failed. Open it to see why.'],
        'data_quality_critical' => ['title' => 'Critical data-quality issue found', 'body' => 'The scan found critical issues (:check). Open Data Quality to work on them.'],
        'security_alert' => ['title' => 'Security alert', 'body' => 'A security event needs attention: open the Security Center.'],
        'court_letter_received' => ['title' => 'Letter from the administrative tribunal', 'body' => 'The administrative tribunal sent your institution a letter on case :case. Open Court letters to read it.'],
    ],
];
