<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// API request logs grow with every integration call; keep 90 days.
Schedule::command('api:prune-logs')->dailyAt('02:30');

// Challenge nonces are only meaningful until they expire; keep a short tail.
Schedule::command('nfc:prune-challenges')->dailyAt('02:45');

// Cafeteria policy status bookkeeping (approved → active, ended → expired).
// Scans follow policy dates, so this only keeps the labels current.
Schedule::command('cafeteria:sync-policy-statuses')->dailyAt('00:05');

// Daily activity reminders. Idempotent, so a frequent cadence only means
// reminders land close to the configured time; each is sent at most once.
Schedule::command('daily-activities:send-reminders')->everyFifteenMinutes()->withoutOverlapping();

// Database backups themselves run independently under the infrastructure scheduler.
Schedule::command('backup:health --monitor')->everyFiveMinutes()->withoutOverlapping();

// Grievance SLA: reminders, automatic escalation of overdue stages, and
// closure after the appeal window when enabled. Idempotent (row locks + a
// unique successor per stage), so overlapping or repeated runs are harmless;
// withoutOverlapping/onOneServer just avoid wasted work.
Schedule::command('grievances:process-sla')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Assessment oversight: outdated submissions and deadline reminders (docs/assessment-oversight.md).
Schedule::command('assessments:oversight-monitor')->dailyAt('06:30')->withoutOverlapping()->onOneServer();

// Assessment execution: evaluator due-soon / overdue reminders (docs/assessment-evaluator-workflow.md).
Schedule::command('assessments:evaluator-reminders')->dailyAt('07:00')->withoutOverlapping()->onOneServer();
