<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportTrip;

/*
 * Moving a module into Modules/ (ADR 0016) must not change what the database
 * and the queue hold: moved models still store their old class names (roles,
 * audit logs, tokens), and old class names still load (queued jobs, stored
 * notification types written before the move).
 */

it('stores a moved model under its old class name and resolves that name back', function (): void {
    expect((new TransportRoute)->getMorphClass())->toBe('App\\Models\\TransportRoute')
        ->and(Relation::getMorphedModel('App\\Models\\TransportRoute'))->toBe(TransportRoute::class)
        ->and((new TransportTrip)->getMorphClass())->toBe('App\\Models\\TransportTrip');
});

it('loads a class by its name from before the move', function (): void {
    expect(class_exists('App\\Models\\TransportRoute'))->toBeTrue()
        ->and(new ('App\\Models\\TransportRoute'))->toBeInstanceOf(TransportRoute::class);
});

it('keeps an alias for every moved class in the legacy map', function (): void {
    $map = require base_path('Modules/legacy-class-map.php');

    expect($map)->not->toBeEmpty();
    foreach ($map as $old => $new) {
        expect(class_exists($new) || enum_exists($new) || interface_exists($new) || trait_exists($new))->toBeTrue("{$new} (was {$old}) does not exist");
    }
});
