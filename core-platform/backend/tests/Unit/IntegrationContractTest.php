<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DomainEvents;
use App\Services\DuplicateEmployees;
use App\Services\WebhookDeliverer;
use PHPUnit\Framework\TestCase;

/** Contracts other systems build on: the webhook signature, the event catalogue, name matching. */
class IntegrationContractTest extends TestCase
{
    public function test_the_webhook_signature_is_hmac_sha256_of_timestamp_dot_body(): void
    {
        $this->assertSame(
            hash_hmac('sha256', '1700000000.{"a":1}', 'whsec_test'),
            WebhookDeliverer::signature('whsec_test', '1700000000', '{"a":1}'),
        );
    }

    public function test_the_event_catalogue_holds_the_published_types_once(): void
    {
        $types = DomainEvents::types();

        $this->assertSame($types, array_values(array_unique($types)));
        foreach (['organization.created', 'organization.archived', 'hierarchy.published', 'position.status.changed', 'employee.created',
            'employee.status.changed', 'assignment.changed', 'assignment.ended', 'card.issued', 'card.activated', 'card.suspended',
            'card.revoked', 'card.replaced'] as $required) {
            $this->assertContains($required, $types);
        }
    }

    public function test_names_match_regardless_of_case_and_spacing(): void
    {
        $this->assertSame(DuplicateEmployees::normalizedName('  Abebe   KEBEDE '), DuplicateEmployees::normalizedName('abebe kebede'));
    }
}
