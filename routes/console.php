<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// API request logs grow with every integration call; keep 90 days.
Schedule::command('api:prune-logs')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// Challenge nonces are only meaningful until they expire; keep a short tail.
Schedule::command('nfc:prune-challenges')->dailyAt('02:45')->withoutOverlapping()->onOneServer();

// Retention (config/retention.php; audit logs follow Security → Audit
// Retention Days). Financial, employee-history and case records are never pruned.
Schedule::command('data:prune-retention')->dailyAt('03:15')->withoutOverlapping()->onOneServer();

// Cafeteria policy status bookkeeping (approved → active, ended → expired).
// Scans follow policy dates, so this only keeps the labels current.
Schedule::command('cafeteria:sync-policy-statuses')->dailyAt('00:05')->withoutOverlapping()->onOneServer();

// Daily activity reminders. Idempotent, so a frequent cadence only means
// reminders land close to the configured time; each is sent at most once.
Schedule::command('daily-activities:send-reminders')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Database backups themselves run independently under the infrastructure scheduler.
Schedule::command('backup:health --monitor')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Grievance SLA: reminders, automatic escalation of overdue stages, and
// closure after the appeal window when enabled. Idempotent (row locks + a
// unique successor per stage), so overlapping or repeated runs are harmless;
// withoutOverlapping/onOneServer just avoid wasted work.
Schedule::command('grievances:process-sla')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Regulation 201/2018 deadlines the institution owes: due-soon and overdue alerts, once each.
Schedule::command('legal:statutory-deadline-alerts')->dailyAt('06:45')->withoutOverlapping()->onOneServer();

// Assessment oversight: outdated submissions and deadline reminders (docs/assessment-oversight.md).
Schedule::command('assessments:oversight-monitor')->dailyAt('06:30')->withoutOverlapping()->onOneServer();

// Assessment execution: evaluator due-soon / overdue reminders (docs/assessment-evaluator-workflow.md).
Schedule::command('assessments:evaluator-reminders')->dailyAt('07:00')->withoutOverlapping()->onOneServer();

// Communication: re-check automatic channel memberships (the assignment observer covers moves as they happen).
Schedule::command('communication:sync-memberships --queue')->dailyAt('03:40')->withoutOverlapping()->onOneServer();
Schedule::command('communication:publish-scheduled')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('communication:expire-announcements')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('communication:expire-investigations')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('communication:prune')->dailyAt('03:55')->withoutOverlapping()->onOneServer();

// Transport (docs/transport/routes-stops-trips.md): plan today's and tomorrow's trips from the
// schedules. Idempotent — a planned trip is never planned twice — so a re-run is harmless.
Schedule::command('transport:plan-trips --days=2')->dailyAt('00:20')->withoutOverlapping()->onOneServer();

// Addis One Intelligence (docs/intelligence-system-health.md): collect telemetry, then detect; prune by retention.
Schedule::command('intelligence:collect --detect')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('intelligence:prune')->dailyAt('03:35')->withoutOverlapping()->onOneServer();
Schedule::command('intelligence:alerts')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('intelligence:forecast --evaluate')->dailyAt('04:10')->withoutOverlapping()->onOneServer();
Schedule::command('assistant:prune')->dailyAt('03:50')->withoutOverlapping()->onOneServer();

// Event integration layer (docs/integration/transactional-outbox.md): outbox →
// change feed every ten seconds without the queue; webhook deliveries fan out
// one queued job per subscription; retention only when a policy is configured.
// The overlap mutex expires after 5 minutes (not the 24-hour default) so a
// crashed run cannot stall the outbox for a day (production-readiness/queue-readiness.md).
Schedule::command('integrations:publish')->everyTenSeconds()->withoutOverlapping(5)->onOneServer();
Schedule::command('integrations:deliver --queue')->everyTenSeconds()->withoutOverlapping(5)->onOneServer();
Schedule::command('integrations:purge')->dailyAt('03:25')->withoutOverlapping()->onOneServer();
