<?php

declare(strict_types=1);

use App\Services\Integration\Events\EventCatalog;

/*
 * The published integration event contract (docs/governance/event-lifecycle.md).
 * tests/Fixtures/contracts/event-catalog-v1.json is the version partners
 * built against. Removing a type or a payload key, or changing a type's
 * aggregate, scope or tombstone rule, is breaking: it needs a new
 * event_version published beside the old one, never an edit in place.
 * An addition is allowed but deliberate: add it to the snapshot in the
 * same change, together with the event catalog document.
 */

function publishedEventContract(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/contracts/event-catalog-v1.json'), true, flags: JSON_THROW_ON_ERROR);
}

function currentEventTypes(): array
{
    return (new ReflectionClassConstant(EventCatalog::class, 'TYPES'))->getValue();
}

it('keeps every published event type compatible', function (): void {
    $published = publishedEventContract();
    $current = currentEventTypes();
    $breaking = [];

    foreach ($published['types'] as $type => $contract) {
        if (! isset($current[$type])) {
            $breaking[] = "{$type}: removed";

            continue;
        }
        foreach (['aggregate', 'scope', 'tombstone'] as $rule) {
            if ($current[$type][$rule] !== $contract[$rule]) {
                $breaking[] = "{$type}: {$rule} changed";
            }
        }
        foreach (array_diff($contract['data'], $current[$type]['data']) as $key) {
            $breaking[] = "{$type}: payload key {$key} removed";
        }
    }

    expect(EventCatalog::VERSION)->toBe($published['event_version'])
        ->and($breaking)->toBe([], 'Breaking event change. Publish a new event_version beside the old one (docs/governance/event-lifecycle.md).');
});

it('records every event addition in the published contract', function (): void {
    $published = publishedEventContract()['types'];
    $additions = [];

    foreach (currentEventTypes() as $type => $contract) {
        if (! isset($published[$type])) {
            $additions[] = "{$type}: new type";

            continue;
        }
        foreach (array_diff($contract['data'], $published[$type]['data']) as $key) {
            $additions[] = "{$type}: new payload key {$key}";
        }
    }

    expect($additions)->toBe([], 'Additive event change: add it to tests/Fixtures/contracts/event-catalog-v1.json and docs/integration/event-catalog.md in the same change.');
});
