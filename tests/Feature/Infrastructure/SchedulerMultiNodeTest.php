<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
 * Production risk register INF (P2): with several app nodes running the
 * scheduler, every task must run on one server and never overlap itself.
 * The jobs are written to be idempotent as well; this is the second guard.
 */
it('runs every scheduled task on one server without overlapping', function (): void {
    $events = app(Schedule::class)->events();
    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        $name = $event->command ?? $event->description ?? 'closure';
        expect($event->onOneServer)->toBeTrue("{$name} lacks onOneServer()")
            ->and($event->withoutOverlapping)->toBeTrue("{$name} lacks withoutOverlapping()");
    }
});
