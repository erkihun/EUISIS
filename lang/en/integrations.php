<?php

declare(strict_types=1);

return [
    'errors' => [
        'application_required' => 'Choose a registered application.',
        'event_types_required' => 'Choose at least one event type.',
        'event_types_not_allowed' => 'The application\'s scopes do not allow: :types.',
        'reason_required' => 'Give a reason for the replay.',
        'subscription_inactive' => 'Enable the subscription before replaying.',
        'replay_criteria_required' => 'Choose failed deliveries, a time range or event IDs.',
        'replay_too_large' => 'More than :max events match. Narrow the range.',
        'url_invalid_url' => 'Enter a valid URL.',
        'url_url_too_long' => 'The URL is too long.',
        'url_https_required' => 'The callback URL must use HTTPS.',
        'url_credentials_in_url' => 'Do not put credentials in the URL.',
        'url_port_not_allowed' => 'This port is not allowed.',
        'url_destination_not_allowed' => 'This destination is a private, loopback, link-local or metadata address and is not allowed.',
        'url_unresolvable_host' => 'The host name does not resolve.',
    ],
    'flash' => [
        'created' => 'Subscription created. Copy the signing secret now — it is shown once.',
        'updated' => 'Subscription updated.',
        'rotated' => 'Secret rotated. Copy the new secret now — the previous one signs until the overlap ends.',
        'status_active' => 'Subscription enabled.',
        'status_disabled' => 'Subscription disabled. History is kept.',
        'test_ok' => 'Test event accepted (HTTP :status, :ms ms).',
        'test_failed' => 'Test event failed (HTTP :status, :category).',
        'replayed' => ':count event(s) queued for replay.',
    ],
    'client_created' => 'API client registered. Copy its secret now; it is not shown again.',
    'client_updated' => 'API client updated.',
    'client_rotated' => 'A new secret was issued and the old one stopped working. Copy it now; it is not shown again.',
    'client_revoked' => 'API client revoked. It can no longer call the Core.',
    'webhook_created' => 'Webhook registered. Copy its signing secret now; it is not shown again.',
    'webhook_updated' => 'Webhook updated.',
    'webhook_rotated' => 'A new signing secret was issued. Copy it now; it is not shown again.',
    'delivery_retried' => 'The delivery is queued to be sent again.',
];
