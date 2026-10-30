<?php

declare(strict_types=1);

use App\Support\Database\ReadOnlyModelException;
use Modules\Providers\Contracts\ReadModels\ProviderRecord;
use Modules\Providers\Domain\Models\Provider;
use Modules\Providers\Domain\Models\ProviderType;
use Modules\Transport\Domain\Models\TransportRoute;

/*
 * A read model an owner publishes (ADR 0017) reads like the owner's model and
 * refuses every write: other modules hold it in relations, never write with it.
 */

beforeEach(function (): void {
    $type = ProviderType::query()->firstOrCreate(['code' => 'TRANSPORT'], ['name_en' => 'Transport', 'is_active' => true]);
    $this->provider = Provider::query()->create([
        'provider_code' => 'TRP-READ', 'provider_type_id' => $type->id, 'name_en' => 'Read Lines', 'name_am' => 'ንባብ',
        'status' => 'active', 'phone_number' => '0911000000', 'metadata' => ['note' => 'x'],
    ]);
});

it('serializes and names itself as the owner model does', function (): void {
    $record = ProviderRecord::query()->findOrFail($this->provider->id);

    expect($record->toArray())->toBe($this->provider->fresh()->toArray())
        ->and($record->getMorphClass())->toBe($this->provider->getMorphClass())
        ->and($record->toArray())->not->toHaveKey('phone_number');
});

it('refuses writes through the model and its query builder', function (): void {
    $record = ProviderRecord::query()->findOrFail($this->provider->id);

    expect(fn () => $record->forceFill(['name_en' => 'Changed'])->save())->toThrow(ReadOnlyModelException::class)
        ->and(fn () => $record->delete())->toThrow(ReadOnlyModelException::class)
        ->and(fn () => $record->forceDelete())->toThrow(ReadOnlyModelException::class)
        ->and(fn () => ProviderRecord::query()->whereKey($this->provider->id)->update(['name_en' => 'Changed']))->toThrow(ReadOnlyModelException::class)
        ->and(fn () => ProviderRecord::query()->whereKey($this->provider->id)->delete())->toThrow(ReadOnlyModelException::class)
        ->and(fn () => ProviderRecord::query()->insert(['id' => 'x']))->toThrow(ReadOnlyModelException::class)
        ->and(fn () => ProviderRecord::query()->create(['name_en' => 'New']))->toThrow(ReadOnlyModelException::class)
        ->and($this->provider->fresh()->name_en)->toBe('Read Lines');
});

it('narrows providers to a type without the owner\'s models', function (): void {
    expect(ProviderRecord::query()->ofType('TRANSPORT')->pluck('id')->all())->toContain($this->provider->id)
        ->and(ProviderRecord::query()->ofType('CAFETERIA')->pluck('id')->all())->not->toContain($this->provider->id);
});

it('backs other modules\' relations, archived providers excluded as before', function (): void {
    $route = TransportRoute::query()->create(['provider_id' => $this->provider->id, 'route_code' => 'R-READ', 'name_en' => 'Read route', 'origin_en' => 'A', 'destination_en' => 'B', 'assigned_scope_type' => 'citywide', 'is_active' => true]);

    expect($route->provider)->toBeInstanceOf(ProviderRecord::class)
        ->and($route->provider->name_en)->toBe('Read Lines');

    $this->provider->delete();

    expect($route->fresh()->provider)->toBeNull()
        ->and(ProviderRecord::withTrashed()->find($this->provider->id))->not->toBeNull();
});
