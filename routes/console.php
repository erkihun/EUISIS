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

// Daily activity reminders. Idempotent, so a frequent cadence only means
// reminders land close to the configured time; each is sent at most once.
Schedule::command('daily-activities:send-reminders')->everyFifteenMinutes()->withoutOverlapping();
