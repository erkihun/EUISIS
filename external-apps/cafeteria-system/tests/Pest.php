<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        /*
         * Serving is only permitted on an open day, so a suite run on a
         * Saturday would fail every scan test for a reason unrelated to what
         * it asserts. Pin the clock to a Wednesday; tests about weekends and
         * holidays travel deliberately from here.
         */
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(12, 0));
    })
    ->in('Feature');
