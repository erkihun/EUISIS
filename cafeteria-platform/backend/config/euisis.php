<?php

declare(strict_types=1);

/*
 * The EUISIS the cafeteria platform belongs to. The platform reads EUISIS over
 * its integration API (docs/integration/consumer-guide.md in EUISIS) with the
 * token of the application registered there for it, and never touches the
 * EUISIS database.
 */
return [
    'url' => rtrim((string) env('EUISIS_URL', ''), '/'),
    'token' => (string) env('EUISIS_TOKEN', ''),
    'timeout_seconds' => (int) env('EUISIS_TIMEOUT_SECONDS', 10),
    // How many records one directory page carries during a sync.
    'page_size' => (int) env('EUISIS_SYNC_PAGE_SIZE', 200),
    // A mirrored card verified more recently than this is accepted without asking EUISIS again.
    'card_fresh_minutes' => (int) env('EUISIS_CARD_FRESH_MINUTES', 5),
    // While EUISIS cannot be reached, a card verified within this window is still accepted.
    'offline_grace_hours' => (int) env('EUISIS_OFFLINE_GRACE_HOURS', 24),
];
