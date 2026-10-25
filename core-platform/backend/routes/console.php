<?php

use Illuminate\Support\Facades\Schedule;

// Cards past their expiry date say so (verification refuses them already).
Schedule::command('core:expire-cards')->dailyAt('00:15')->withoutOverlapping()->onOneServer();

// Core events reach their webhook receivers; failures retry with back-off.
Schedule::command('core:deliver-webhooks')->everyMinute()->withoutOverlapping()->onOneServer();
