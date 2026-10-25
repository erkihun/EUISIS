<?php

use Illuminate\Support\Facades\Schedule;

// Cards past their expiry date say so (verification refuses them already).
Schedule::command('core:expire-cards')->dailyAt('00:15')->withoutOverlapping()->onOneServer();

// Core events reach their webhook receivers; failures retry with back-off.
Schedule::command('core:deliver-webhooks')->everyMinute()->withoutOverlapping()->onOneServer();

// The audit trail's tamper evidence (ADR 0010): new rows are sealed every few minutes, the whole chain checked nightly.
Schedule::command('core:audit-seal --all')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// Each seal's head is also copied off the database server (ADR 0012); a failed run is caught up by the next.
Schedule::command('core:audit-ship-heads')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('core:audit-verify')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// Break-glass access ends on time even if nobody looks (ADR 0010).
Schedule::command('core:break-glass-expire')->everyMinute()->withoutOverlapping()->onOneServer();

// Delivered integration events and old API call logs leave after their retention (Core settings › Integration).
Schedule::command('core:prune-integration-data')->dailyAt('03:15')->withoutOverlapping()->onOneServer();

// Data-quality issues follow the integrity checks (ADR 0011).
Schedule::command('core:notify-expiring')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('core:data-quality-scan')->hourlyAt(20)->withoutOverlapping()->onOneServer();
Schedule::command('core:prune-exports')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
