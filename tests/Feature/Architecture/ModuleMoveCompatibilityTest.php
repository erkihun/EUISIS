<?php

declare(strict_types=1);

use App\Support\NotificationPresenter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
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

it('words a stored transport notification through the renderer the module registers', function (): void {
    // A row written before the move: its type names the old class, its data names the module.
    $stored = new DatabaseNotification([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TransportNotification',
        'data' => ['module' => 'transport', 'kind' => 'trip_cancelled', 'context' => ['route_code' => 'R-7', 'route_name_en' => 'Bole', 'date' => '2026-10-30'], 'url' => '/my-portal/services'],
    ]);

    $shown = NotificationPresenter::present($stored, 'en');

    expect(class_exists($stored->type))->toBeTrue()
        ->and($shown['title'])->toBe(__('transport.notifications.title', [], 'en'))
        ->and($shown['message'])->toContain('R-7')->toContain('2026-10-30')
        ->and($shown['url'])->toBe('/my-portal/services');
});

it('keeps an alias for every moved class in the legacy map', function (): void {
    $map = require base_path('Modules/legacy-class-map.php');

    expect($map)->not->toBeEmpty();
    foreach ($map as $old => $new) {
        expect(class_exists($new) || enum_exists($new) || interface_exists($new) || trait_exists($new))->toBeTrue("{$new} (was {$old}) does not exist")
            ->and($old)->not->toBe($new, "{$old} maps to itself: record its name from before the move")
            ->and($old)->not->toStartWith('Modules\\', "{$old} is a current name, not one from before the move");
    }
});
