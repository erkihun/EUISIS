<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class CoreServiceTest extends TestCase
{
    public function test_the_root_describes_the_api_and_carries_a_request_id(): void
    {
        $this->getJson('/')->assertOk()->assertJsonStructure(['service', 'api', 'health'])->assertHeader('X-Request-ID');
    }

    public function test_the_health_check_answers(): void
    {
        $this->get('/up')->assertOk();
    }
}
