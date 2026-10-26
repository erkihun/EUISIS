<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The pages render without built assets: CI has no public/build, and a
        // missing Vite manifest would turn every Inertia page into a 500.
        $this->withoutVite();
    }
}
