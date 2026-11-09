<?php

declare(strict_types=1);

use App\Actions\Organizations\CreateOrganizationAction;
use App\Actions\OrganizationTypes\CreateOrganizationTypeAction;
use App\Models\CodeRule;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Organizations\AmharicOrganizationCodes;
use Database\Seeders\CodeRuleSeeder;

beforeEach(function (): void {
    $this->seed(CodeRuleSeeder::class);
    $this->actor = User::factory()->create();
});

function amharicType(User $actor, string $nameAm): OrganizationType
{
    return app(CreateOrganizationTypeAction::class)->execute(['name_en' => 'Type '.$nameAm, 'name_am' => $nameAm], $actor);
}

function amharicOrganization(User $actor, OrganizationType $type, string $nameAm): Organization
{
    return app(CreateOrganizationAction::class)->execute([
        'organization_type_id' => $type->getKey(), 'name_en' => 'Org '.$nameAm, 'name_am' => $nameAm, 'status' => 'active',
    ], $actor);
}

test('a new organization type takes the first Amharic letter of its name as prefix and in its code', function (): void {
    $type = amharicType($this->actor, 'ቢሮ');

    expect($type->prefix)->toBe('ቢ')
        ->and($type->code)->toMatch('/^ቢ-\d+$/u');
});

test('an organization code is the type prefix and a sequence counted per prefix', function (): void {
    $bureau = amharicType($this->actor, 'ቢሮ');
    $agency = amharicType($this->actor, 'ኤጀንሲ');

    expect(amharicOrganization($this->actor, $bureau, 'የጤና ቢሮ')->code)->toBe('ቢ-01')
        ->and(amharicOrganization($this->actor, $bureau, 'የንግድ ቢሮ')->code)->toBe('ቢ-02')
        ->and(amharicOrganization($this->actor, $agency, 'የጽዳት ኤጀንሲ')->code)->toBe('ኤ-01');
});

test('two types that start with the same letter never produce the same organization code', function (): void {
    $mayorOffice = amharicType($this->actor, 'የከንቲባ ጽህፈት ቤት');
    $youthCentre = amharicType($this->actor, 'የወጣቶች ማዕከል');

    expect($mayorOffice->code)->not->toBe($youthCentre->code)
        ->and(amharicOrganization($this->actor, $mayorOffice, 'ሀ')->code)->toBe('የ-01')
        ->and(amharicOrganization($this->actor, $youthCentre, 'ለ')->code)->toBe('የ-02');
});

test('commission and corporation have their own prefixes and sequences', function (): void {
    $commission = amharicType($this->actor, 'ኮሚሽን');
    $corporation = amharicType($this->actor, 'ኮርፖሬሽን');

    expect($commission->prefix)->toBe('ኮሚ')
        ->and($corporation->prefix)->toBe('ኮር')
        ->and(amharicOrganization($this->actor, $commission, 'የህብረት ሥራ ኮሚሽን')->code)->toBe('ኮሚ-01')
        ->and(amharicOrganization($this->actor, $corporation, 'የቤቶች ልማት ኮርፖሬሽን')->code)->toBe('ኮር-01');
});

test('a chosen prefix is kept and a type without an Amharic name uses the rule prefix', function (): void {
    $chosen = app(CreateOrganizationTypeAction::class)->execute(['name_en' => 'Chosen', 'name_am' => 'ቢሮ', 'prefix' => 'ቢሮ'], $this->actor);
    $english = app(CreateOrganizationTypeAction::class)->execute(['name_en' => 'English only'], $this->actor);

    expect($chosen->prefix)->toBe('ቢሮ')
        ->and($chosen->code)->toMatch('/^ቢሮ-\d+$/u')
        ->and($english->prefix)->toBeNull()
        ->and($english->code)->toMatch('/^OT-\d+$/');
});

test('codes made at a longer length shrink to two digits and keep their number', function (): void {
    $bureau = amharicType($this->actor, 'ቢሮ');
    $organization = Organization::query()->create(['organization_type_id' => $bureau->getKey(), 'code' => 'ቢ-00014', 'name_en' => 'Older', 'status' => 'active']);
    $bureau->forceFill(['code' => 'ቢ-0004'])->save();

    app(AmharicOrganizationCodes::class)->apply($this->actor);

    expect($organization->fresh()->code)->toBe('ቢ-14')
        ->and($bureau->fresh()->code)->toBe('ቢ-04')
        ->and(CodeRule::query()->where('entity_type', 'organization')->value('sequence_length'))->toBe(2);
});

test('the abbreviated types use their own prefix instead of the first letter', function (): void {
    $expected = ['ከተማ አስተዳደር' => 'ከማ', 'ምክር ቤት' => 'ምቤ', 'የልማት ድርጅት' => 'ልድ', 'አገልግሎት ሰጪ' => 'አሰ'];

    foreach ($expected as $name => $prefix) {
        $type = amharicType($this->actor, $name);

        expect($type->prefix)->toBe($prefix)
            ->and($type->code)->toMatch('/^'.$prefix.'-\d{2}$/u')
            ->and(amharicOrganization($this->actor, $type, 'ተቋም '.$name)->code)->toBe($prefix.'-01');
    }
});

test('changing a type prefix moves the type and its organizations to the new prefix with a fresh sequence', function (): void {
    $type = amharicType($this->actor, 'ኤጀንሲ');
    $first = amharicOrganization($this->actor, $type, 'ሀ');
    $second = amharicOrganization($this->actor, $type, 'ለ');
    $number = explode('-', $type->code)[1];

    $type->update(['prefix' => 'ኤጀ']);
    app(AmharicOrganizationCodes::class)->apply($this->actor);

    expect($type->fresh()->code)->toBe('ኤጀ-'.$number)
        ->and($first->fresh()->code)->toBe('ኤጀ-01')
        ->and($second->fresh()->code)->toBe('ኤጀ-02')
        ->and(app(AmharicOrganizationCodes::class)->apply($this->actor))->toBe(['types' => 0, 'organizations' => 0]);
});

test('a known type gets its category, a chosen category is kept and a blank one on an old type is filled', function (): void {
    $chosen = app(CreateOrganizationTypeAction::class)->execute(['name_en' => 'Chosen', 'name_am' => 'ቢሮ', 'category' => 'other'], $this->actor);
    $old = OrganizationType::query()->create(['code' => 'WOREDA', 'prefix' => 'WOR', 'name_en' => 'Woreda']);
    $edited = OrganizationType::query()->create(['code' => 'SECTOR', 'prefix' => 'SEC', 'name_en' => 'Sector', 'name_am' => 'ዘርፍ', 'category' => 'other']);

    app(AmharicOrganizationCodes::class)->apply($this->actor);

    expect(amharicType($this->actor, 'ክፍለ ከተማ')->category)->toBe('geographic')
        ->and(amharicType($this->actor, 'ኮሚሽን')->category)->toBe('independent')
        ->and(amharicType($this->actor, 'የማይታወቅ ዓይነት')->category)->toBeNull()
        ->and($chosen->fresh()->category)->toBe('other')
        ->and($old->fresh()->category)->toBe('geographic')
        ->and($edited->fresh()->category)->toBe('other');
});

test('existing records move to the scheme once, parent rules follow and archived organizations keep their code', function (): void {
    CodeRule::query()->where('entity_type', 'organization')->update(['format' => '{PREFIX}-{YEAR}-{SEQUENCE}', 'prefix' => 'ORG']);
    $bureau = OrganizationType::query()->create(['code' => 'BUREAU', 'prefix' => 'BUR', 'name_en' => 'Bureau']);
    $unit = OrganizationType::query()->create(['code' => 'CHILD', 'name_en' => 'Child', 'parent_allowed_types' => ['BUREAU']]);
    $live = Organization::query()->create(['organization_type_id' => $bureau->getKey(), 'code' => 'BUREAU-2026-00001', 'name_en' => 'Live', 'status' => 'active']);
    $archived = Organization::query()->create(['organization_type_id' => $bureau->getKey(), 'code' => 'OLD-1', 'name_en' => 'Old', 'status' => 'archived']);
    $archived->delete();

    $first = app(AmharicOrganizationCodes::class)->apply($this->actor);
    $second = app(AmharicOrganizationCodes::class)->apply($this->actor);

    $bureau->refresh();
    expect($first['organizations'])->toBe(1)
        ->and($second)->toBe(['types' => 0, 'organizations' => 0])
        ->and($bureau->name_am)->toBe('ቢሮ')
        ->and($bureau->prefix)->toBe('ቢ')
        ->and($bureau->code)->toMatch('/^ቢ-\d+$/u')
        ->and($unit->fresh()->parent_allowed_types)->toBe([$bureau->code])
        ->and($live->fresh()->code)->toBe('ቢ-01')
        ->and($archived->fresh()->code)->toBe('OLD-1');
});
