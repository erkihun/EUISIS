<?php

declare(strict_types=1);

use App\Enums\CafeteriaLocationType;
use App\Models\CafeteriaServiceNetwork;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\Cafeteria\Network\CafeteriaNetworkService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    $this->s = CafeteriaScenario::make();
    $this->networks = app(CafeteriaNetworkService::class);
});

test('1 a provider can have a network', function (): void {
    $network = $this->networks->createNetwork([
        'provider_id' => $this->s->provider->id, 'code' => 'net-two', 'name_en' => 'Second network',
    ], User::factory()->create());

    expect($network->code)->toBe('NET-TWO')
        ->and($this->s->provider->cafeteriaNetworks()->pluck('id')->all())->toContain($network->id, $this->s->network->id);
});

test('2 a network has one main cafeteria', function (): void {
    expect($this->s->network->mainCafeteria->id)->toBe($this->s->main->id);

    expect(fn () => $this->networks->assertPlacement(null, $this->s->provider->id, $this->s->network->id, null, CafeteriaLocationType::Main))
        ->toThrow(ValidationException::class);
});

test('3 a network can have branches and service points under its main cafeteria', function (): void {
    $point = $this->s->location('Kiosk', CafeteriaLocationType::ServicePoint, $this->s->branch);

    $tree = $this->networks->tree($this->s->network);

    expect($tree)->toHaveCount(1)
        ->and($tree[0]['id'])->toBe($this->s->main->id)
        ->and($tree[0]['children'][0]['id'])->toBe($this->s->branch->id)
        ->and($tree[0]['children'][0]['children'][0]['id'])->toBe($point->id);
});

test('4 a branch cannot belong to an unrelated provider or another network', function (): void {
    $other = CafeteriaScenario::make('B');

    expect(fn () => $this->networks->assertPlacement(null, $other->provider->id, $this->s->network->id, null, CafeteriaLocationType::Branch))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->networks->assertPlacement(null, $this->s->provider->id, $this->s->network->id, $other->main->id, CafeteriaLocationType::Branch))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->networks->assertPlacement(null, $this->s->provider->id, null, null, CafeteriaLocationType::Branch))
        ->toThrow(ValidationException::class);
});

test('5 a circular parent chain is rejected', function (): void {
    $point = $this->s->location('Kiosk', CafeteriaLocationType::ServicePoint, $this->s->branch);

    // Branch → Kiosk would make Kiosk (a child of Branch) its parent.
    expect(fn () => $this->networks->assertPlacement($this->s->branch, $this->s->provider->id, $this->s->network->id, $point->id, CafeteriaLocationType::Branch))
        ->toThrow(ValidationException::class)
        // ...and a location is never its own parent.
        ->and(fn () => $this->networks->assertPlacement($this->s->branch, $this->s->provider->id, $this->s->network->id, $this->s->branch->id, CafeteriaLocationType::Branch))
        ->toThrow(ValidationException::class);
});

test('6 an inactive or closed branch cannot serve a transaction', function (): void {
    $org = $this->s->organization('Organization 2');
    $this->s->enroll($org, '150.00', $this->s->branch);
    [, $card] = $this->s->employee($org);
    $scan = fn () => app(CafeteriaQrScanService::class)->process($card, $this->s->branch->fresh(), Carbon::parse('2026-09-21 12:00'));

    $this->s->branch->forceFill(['is_active' => false])->save();
    expect($scan()['denial_reason'])->toBe('cafeteria_inactive');

    $this->s->branch->forceFill(['is_active' => true, 'operational_status' => 'temporarily_closed'])->save();
    expect($scan()['denial_reason'])->toBe('cafeteria_temporarily_closed');

    // The network itself being inactive also stops service.
    $this->s->branch->forceFill(['operational_status' => 'open'])->save();
    CafeteriaServiceNetwork::query()->whereKey($this->s->network->id)->update(['status' => 'inactive']);
    expect($scan()['denial_reason'])->toBe('cafeteria_not_in_network');
});
