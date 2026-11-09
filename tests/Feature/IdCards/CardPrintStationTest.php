<?php

declare(strict_types=1);

use App\Enums\CardStatus;
use App\Enums\OrganizationScopeType;
use App\Models\CardPrintBatch;
use App\Models\CardPrinterProfile;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\GrievanceScenario;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
    foreach (['id-cards.createPrintBatch', 'id-cards.print', 'id-cards.view', 'cards.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $type = OrganizationType::query()->firstOrCreate(['code' => 'CPS'], ['name_en' => 'Print station test']);
    $this->bureau = GrievanceScenario::org($type, 'Bureau');
    $this->other = GrievanceScenario::org($type, 'Other bureau');
    $this->directorate = GrievanceScenario::unit($this->bureau, 'team', 'Directorate');
    $this->team = GrievanceScenario::unit($this->bureau, 'team', 'Team', $this->directorate);
    $this->desk = GrievanceScenario::unit($this->bureau, 'team', 'Desk');

    $this->inDirectorate = stationCard(GrievanceScenario::employee($this->bureau, $this->directorate));
    $this->inTeam = stationCard(GrievanceScenario::employee($this->bureau, $this->team));
    $this->atDesk = stationCard(GrievanceScenario::employee($this->bureau, $this->desk));
    $this->elsewhere = stationCard(GrievanceScenario::employee($this->other, null));

    $this->operator = User::factory()->create();
    $this->operator->givePermissionTo(['id-cards.createPrintBatch', 'id-cards.print', 'id-cards.view', 'cards.view']);
});

function stationCard(Employee $employee, CardStatus $status = CardStatus::PendingPrint): IdCard
{
    return IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-'.Str::upper(Str::random(8)),
        'status' => $status,
        'is_current' => true,
        'token_hash' => hash('sha256', Str::random(32)),
    ]);
}

function preparedBatch(array $cards, User $actor): CardPrintBatch
{
    actingAs($actor)->post(route('print-batches.bulk.store'), [
        'mode' => 'selected', 'need' => 'all', 'card_ids' => array_map(fn (IdCard $card) => $card->id, $cards),
    ])->assertSessionHasNoErrors();
    $batch = CardPrintBatch::query()->latest('created_at')->firstOrFail();
    actingAs($actor)->post(route('print-batches.prepare', $batch))->assertSessionHasNoErrors();

    return $batch->fresh();
}

it('lists cards by organization and by a unit with its sub-units', function (): void {
    actingAs($this->operator)
        ->get(route('print-batches.bulk', ['organization_id' => $this->bureau->id, 'organization_unit_id' => $this->directorate->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('IdCards/BulkPrint')
            ->where('total', 2)
            ->has('units', 3)
            ->missing('cards.data.0.employee_phone'));

    actingAs($this->operator)
        ->get(route('print-batches.bulk', ['organization_id' => $this->bureau->id, 'organization_unit_id' => $this->directorate->id, 'include_sub_units' => 0]))
        ->assertInertia(fn (Assert $page) => $page->where('total', 1)->where('cards.data.0.id', $this->inDirectorate->id));

    actingAs($this->operator)
        ->get(route('print-batches.bulk', ['organization_id' => $this->bureau->id]))
        ->assertInertia(fn (Assert $page) => $page->where('total', 3));
});

it('shows a scoped officer only their own organization', function (): void {
    UserOrganizationScope::query()->create([
        'user_id' => $this->operator->id, 'organization_id' => $this->other->id,
        'scope_type' => OrganizationScopeType::Self, 'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    actingAs($this->operator->fresh())
        ->get(route('print-batches.bulk'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('total', 1)
            ->where('cards.data.0.id', $this->elsewhere->id)
            ->has('organizations', 1));
});

it('batches everything matching and opens it at the print station', function (): void {
    actingAs($this->operator)
        ->post(route('print-batches.bulk.store'), ['mode' => 'matching', 'organization_id' => $this->bureau->id])
        ->assertRedirect();

    $batch = CardPrintBatch::query()->sole();
    expect($batch->items()->pluck('id_card_id')->sort()->values()->all())
        ->toBe(collect([$this->inDirectorate->id, $this->inTeam->id, $this->atDesk->id])->sort()->values()->all());

    // Batched cards leave the selection, so a second run cannot print them twice.
    actingAs($this->operator)
        ->get(route('print-batches.bulk', ['organization_id' => $this->bureau->id]))
        ->assertInertia(fn (Assert $page) => $page->where('total', 0));
});

it('refuses a selected card the selection does not allow', function (): void {
    UserOrganizationScope::query()->create([
        'user_id' => $this->operator->id, 'organization_id' => $this->bureau->id,
        'scope_type' => OrganizationScopeType::Self, 'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    actingAs($this->operator->fresh())
        ->post(route('print-batches.bulk.store'), ['mode' => 'selected', 'need' => 'all', 'card_ids' => [$this->inTeam->id, $this->elsewhere->id]])
        ->assertSessionHasErrors('card_ids');

    expect(CardPrintBatch::query()->count())->toBe(0);
});

it('batches reprints of active cards but never revoked ones', function (): void {
    $active = stationCard(GrievanceScenario::employee($this->bureau, null), CardStatus::Active);
    $revoked = stationCard(GrievanceScenario::employee($this->bureau, null), CardStatus::Revoked);
    $active->update(['reprint_required' => true]);

    actingAs($this->operator)
        ->get(route('print-batches.bulk', ['need' => 'reprint_required']))
        ->assertInertia(fn (Assert $page) => $page->where('total', 1)->where('cards.data.0.id', $active->id));

    actingAs($this->operator)
        ->post(route('print-batches.bulk.store'), ['mode' => 'selected', 'need' => 'all', 'card_ids' => [$revoked->id]])
        ->assertSessionHasErrors('card_ids');
});

it('freezes every card in chunks before it prints', function (): void {
    $batch = preparedBatch([$this->inDirectorate, $this->inTeam], $this->operator);

    expect($batch->status)->toBe('printing')
        ->and($batch->items()->whereNull('id_card_print_snapshot_id')->count())->toBe(0);
    foreach ($batch->items()->with('snapshot')->get() as $item) {
        Storage::disk('local')->assertExists($item->snapshot->artifact_path.'-front.svg');
    }

    actingAs($this->operator)->get(route('print-batches.station', $batch))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('PrintBatches/Station')->where('toPrepare', 0)->has('items', 2));
});

it('lays the prepared cards out for the chosen printer profile and range', function (): void {
    $batch = preparedBatch([$this->inDirectorate, $this->inTeam, $this->atDesk], $this->operator);
    $profile = CardPrinterProfile::query()->create(['name' => 'A4 desk', 'mode' => 'sheet', 'paper' => 'a4', 'margin_mm' => 6, 'gap_mm' => 3]);

    actingAs($this->operator)
        ->get(route('print-batches.sheet', ['batch' => $batch->id, 'profile' => $profile->id, 'from' => 2, 'to' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PrintBatches/Sheet')
            ->has('cards', 2)
            ->where('range', ['from' => 2, 'to' => 3])
            // Ten ID-1 cards fit an A4 sheet at these margins.
            ->where('grid.per_page', 10)
            ->where('profile.mode', 'sheet'));
});

it('downloads the frozen faces and a manifest as one zip', function (): void {
    $batch = preparedBatch([$this->inDirectorate, $this->inTeam], $this->operator);

    $response = actingAs($this->operator)->get(route('print-batches.download', $batch));
    $response->assertOk()->assertHeader('Content-Type', 'application/zip');

    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $names = array_map(fn (int $index) => $zip->getNameIndex($index), range(0, $zip->numFiles - 1));
    expect($names)->toContain('manifest.csv')
        ->and(count(array_filter($names, fn ($name) => str_ends_with($name, '-front.svg'))))->toBe(2)
        ->and($zip->getFromName('manifest.csv'))->toContain($this->inDirectorate->card_number);
    $zip->close();
});

it('records each card as printed or spoiled and reprints the spoiled ones', function (): void {
    $batch = preparedBatch([$this->inDirectorate, $this->inTeam], $this->operator);
    [$good, $bad] = $batch->items()->orderBy('created_at')->orderBy('id')->get()->all();

    actingAs($this->operator)
        ->post(route('print-batches.results', $batch), [
            'printed' => [$good->id, $bad->id],
            'spoiled' => [['item_id' => $bad->id, 'reason' => 'Ribbon streak']],
            'printer_notes' => 'Evolis desk 2',
        ])
        ->assertSessionHasNoErrors();

    $batch->refresh();
    expect($batch->status)->toBe('completed')
        ->and($batch->printed_count)->toBe(1)
        ->and($batch->spoiled_count)->toBe(1)
        ->and($good->fresh()->status)->toBe('printed')
        ->and($good->card->fresh()->status)->toBe(CardStatus::Printed)
        ->and($good->snapshot->fresh()->printed_at)->not->toBeNull()
        ->and($bad->fresh()->reprint_reason)->toBe('Ribbon streak')
        ->and($bad->card->fresh()->status)->toBe(CardStatus::PendingPrint);

    actingAs($this->operator)->post(route('print-batches.requeue', $batch))->assertRedirect();
    $again = CardPrintBatch::query()->whereKeyNot($batch->id)->sole();
    expect($again->items()->pluck('id_card_id')->all())->toBe([$bad->id_card_id]);
});

it('keeps one default printer profile and needs the print permission', function (): void {
    $payload = [
        'name' => 'Card printer', 'mode' => 'card', 'paper' => null, 'sides' => 'both', 'duplex_order' => 'interleaved',
        'offset_x_mm' => 0.5, 'offset_y_mm' => -0.3, 'scale_percent' => 100, 'margin_mm' => 0, 'gap_mm' => 0,
        'cut_guides' => false, 'mirror_backs' => false, 'is_default' => true, 'notes' => null,
    ];
    actingAs($this->operator)->post(route('printer-profiles.store'), $payload)->assertSessionHasNoErrors();
    actingAs($this->operator)->post(route('printer-profiles.store'), [...$payload, 'name' => 'Sheet', 'mode' => 'sheet', 'paper' => 'a4'])->assertSessionHasNoErrors();

    expect(CardPrinterProfile::query()->where('is_default', true)->pluck('name')->all())->toBe(['Sheet']);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('id-cards.createPrintBatch');
    actingAs($viewer)->post(route('printer-profiles.store'), $payload)->assertForbidden();
    actingAs($viewer)->post(route('printer-profiles.store'), [...$payload, 'offset_x_mm' => 50])->assertForbidden();
    actingAs($this->operator)->post(route('printer-profiles.store'), [...$payload, 'offset_x_mm' => 50])->assertSessionHasErrors('offset_x_mm');
});

it('fits cards to a sheet from margins, gaps and scale', function (): void {
    $profile = new CardPrinterProfile(['mode' => 'sheet', 'paper' => 'a4', 'margin_mm' => 6, 'gap_mm' => 3, 'scale_percent' => 100]);

    // The usual 10-up A4 sheet; a wider margin costs a row.
    expect($profile->grid(85.6, 54))->toMatchArray(['columns' => 2, 'rows' => 5, 'per_page' => 10])
        ->and($profile->grid(54, 85.6))->toMatchArray(['columns' => 3, 'rows' => 3, 'per_page' => 9])
        ->and((new CardPrinterProfile(['mode' => 'sheet', 'paper' => 'a4', 'margin_mm' => 8, 'gap_mm' => 3, 'scale_percent' => 100]))->grid(85.6, 54))
        ->toMatchArray(['rows' => 4, 'per_page' => 8])
        ->and((new CardPrinterProfile(['mode' => 'card', 'scale_percent' => 100]))->grid(85.6, 54))
        ->toMatchArray(['per_page' => 1, 'page_w' => 85.6, 'page_h' => 54.0]);
});
