<?php

declare(strict_types=1);

/*
 * Communication & Collaboration infrastructure (docs/communication-module.md).
 * Behaviour rules (edit windows, scope, attachment types, retention) are system
 * settings, editable at runtime; only deployment wiring lives here.
 */
return [
    // A private disk: `local` in development, a private S3-compatible bucket in production.
    'attachments_disk' => env('COMMUNICATION_ATTACHMENTS_DISK', 'local'),

    // Rows per page for message history (cursor pagination) and conversation lists.
    'page_size' => (int) env('COMMUNICATION_PAGE_SIZE', 40),

    // Read receipts and per-member delivery state are shown only for conversations up to this size.
    'receipts_max_members' => (int) env('COMMUNICATION_RECEIPTS_MAX_MEMBERS', 25),

    // Largest group an employee may create or grow (channels have no limit).
    'max_group_members' => (int) env('COMMUNICATION_MAX_GROUP_MEMBERS', 256),

    // Pins kept per conversation.
    'max_pins' => 50,

    // Emoji a reaction may use (the picker offers exactly these).
    'reactions' => ['👍', '❤️', '😂', '👏', '✅', '👀', '🎉', '🙏'],

    /*
     | Queue that carries realtime broadcasts. Null uses the default queue;
     | a dedicated queue (e.g. "broadcasts") keeps chat delivery quick when
     | other jobs are busy, but then needs its own `queue:work --queue=...`.
     */
    'broadcast_queue' => env('COMMUNICATION_BROADCAST_QUEUE'),
];
