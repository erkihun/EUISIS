<?php

use Illuminate\Support\Facades\Schedule;

// Cards past their expiry date say so (verification refuses them already).
Schedule::command('core:expire-cards')->dailyAt('00:15')->withoutOverlapping()->onOneServer();
