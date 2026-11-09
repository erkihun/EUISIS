<?php

declare(strict_types=1);

use App\Models\InstitutionMandate;
use App\Models\LegalSource;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\User;
use Database\Seeders\AddisAbabaProclamationInstitutionSeeder;
use Database\Seeders\AddisAbabaProclamationMandateSeeder as MandateSeeder;
use Database\Seeders\CodeRuleSeeder;
use Database\Seeders\LegalInstrumentTypeSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(CodeRuleSeeder::class);
    $this->seed(LegalInstrumentTypeSeeder::class);
    Role::findOrCreate('Super Admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole('Super Admin');
    $this->seed(AddisAbabaProclamationInstitutionSeeder::class);
});

function proclamationMandateOf(string $nameAm): InstitutionMandate
{
    $organization = Organization::query()->where('name_am', $nameAm)->firstOrFail();

    return InstitutionMandate::query()->where('organization_id', $organization->getKey())->firstOrFail();
}

test('every institution with a legal basis gets a draft mandate citing the proclamation', function (): void {
    $this->seed(MandateSeeder::class);

    $source = LegalSource::query()->where('code', MandateSeeder::SOURCE_CODE)->firstOrFail();
    $health = proclamationMandateOf('የጤና ቢሮ');

    expect(InstitutionMandate::query()->count())->toBe(69)
        ->and(InstitutionMandate::query()->where('status', '!=', 'draft')->count())->toBe(0)
        ->and(InstitutionMandate::query()->whereNull('approved_by')->count())->toBe(69)
        ->and($health->legal_source_id)->toBe($source->getKey())
        ->and($health->legal_instrument_number)->toBe('94/2019')
        ->and($health->article_reference)->toBe('11(15), 31')
        ->and($health->effective_from->toDateString())->toBe('2026-09-30');
});

test('each numbered item becomes a function and each lettered item a sub-function, in the proclamation words', function (): void {
    $this->seed(MandateSeeder::class);

    $health = proclamationMandateOf('የጤና ቢሮ');
    $fifth = OrganizationFunction::query()->where('mandate_id', $health->getKey())->where('code', 'ART-31-05')->firstOrFail();

    expect(OrganizationFunction::query()->where('mandate_id', $health->getKey())->count())->toBe(20)
        ->and($fifth->title_am)->toContain('የእናቶችና ህፃናት ጤና')
        ->and($fifth->legal_reference)->toBe('አዋጅ ቁጥር 94/2019 አንቀጽ 31(5)')
        ->and($fifth->title_en)->toBe('Proclamation 94/2019, Article 31(5)')
        ->and($fifth->power?->code)->toBe('ART-31');

    $land = proclamationMandateOf('የመሬት ልማትና አስተዳደር ቢሮ');
    $parents = OrganizationFunction::query()->where('mandate_id', $land->getKey())->whereNull('parent_function_id')->count();
    $children = OrganizationFunction::query()->where('mandate_id', $land->getKey())->whereNotNull('parent_function_id')->get();

    expect($parents)->toBe(24)
        ->and($children)->toHaveCount(10)
        ->and($children->first()->depth)->toBe(1)
        ->and($children->first()->parent?->code)->toStartWith('ART-27-');
});

test('the common article is one power on every article 11 body, and bodies under their own law get no functions', function (): void {
    $this->seed(MandateSeeder::class);

    $common = OrganizationPower::query()->where('code', 'ART-16')->get();
    $police = proclamationMandateOf('የአዲስ አበባ ከተማ ፖሊስ ኮሚሽን');
    $root = proclamationMandateOf('የአዲስ አበባ ከተማ አስተዳደር');
    $bole = proclamationMandateOf('ቦሌ ክፍለ ከተማ');

    expect($common)->toHaveCount(42)
        ->and($common->first()->description_am)->toContain('እቅድን በሚመለከት')
        ->and(OrganizationFunction::query()->where('code', 'like', 'ART-16%')->count())->toBe(0)
        ->and(OrganizationFunction::query()->where('mandate_id', $police->getKey())->count())->toBe(0)
        ->and(OrganizationPower::query()->where('mandate_id', $root->getKey())->pluck('code')->sort()->values()->all())->toBe(['ART-5', 'ART-7'])
        ->and(OrganizationFunction::query()->where('mandate_id', $bole->getKey())->count())->toBe(44);
});

test('bodies the proclamation only names get no mandate, and a re-run adds nothing', function (): void {
    $this->seed(MandateSeeder::class);
    $functions = OrganizationFunction::query()->count();
    $this->seed(MandateSeeder::class);

    $council = Organization::query()->where('name_am', 'የአዲስ አበባ ከተማ ምክር ቤት')->firstOrFail();

    expect(InstitutionMandate::query()->where('organization_id', $council->getKey())->exists())->toBeFalse()
        ->and(InstitutionMandate::query()->count())->toBe(69)
        ->and(OrganizationFunction::query()->count())->toBe($functions)
        ->and(LegalSource::query()->count())->toBe(1);
});

test('every mandate carries the proclamation text of what that institution was given', function (): void {
    $this->seed(MandateSeeder::class);

    $health = proclamationMandateOf('የጤና ቢሮ')->description_am;

    expect(InstitutionMandate::query()->whereNull('description_am')->count())->toBe(0)
        ->and($health)->toContain('አንቀጽ 31፦')
        ->and($health)->toContain('5. የእናቶችና ህፃናት ጤና')
        ->and($health)->toContain('አንቀጽ 16፦')
        ->and($health)->toContain('ሀ) ')
        ->and(proclamationMandateOf('የአዲስ አበባ ከተማ ፖሊስ ኮሚሽን')->description_am)->toContain('ደንብ ቁጥር 96/1996')
        ->and(proclamationMandateOf('የቄራዎች ድርጅት')->description_am)->toContain('አንቀጽ 63፦')
        ->and(proclamationMandateOf('ቦሌ ክፍለ ከተማ')->description_am)->toContain('አንቀጽ 76፦')
        ->and(proclamationMandateOf('ቦሌ ክፍለ ከተማ')->description_am)->not->toContain('አንቀጽ 16፦');
});

test('a re-run fills a blank draft description and leaves written ones and published mandates alone', function (): void {
    $this->seed(MandateSeeder::class);

    $blank = proclamationMandateOf('የጤና ቢሮ');
    $written = proclamationMandateOf('የንግድ ቢሮ');
    $published = proclamationMandateOf('የገቢዎች ቢሮ');
    InstitutionMandate::query()->whereKey($blank->getKey())->update(['description_am' => null]);
    InstitutionMandate::query()->whereKey($written->getKey())->update(['description_am' => 'የተጻፈ']);
    InstitutionMandate::query()->whereKey($published->getKey())->update(['description_am' => null, 'status' => 'published']);

    $this->seed(MandateSeeder::class);

    expect($blank->fresh()->description_am)->toContain('አንቀጽ 31፦')
        ->and($written->fresh()->description_am)->toBe('የተጻፈ')
        ->and($published->fresh()->description_am)->toBeNull();
});

test('an institution with two articles keeps each article together', function (): void {
    $this->seed(MandateSeeder::class);

    $codes = OrganizationFunction::query()
        ->where('mandate_id', proclamationMandateOf('የአዲስ አበባ ከተማ አስተዳደር')->getKey())
        ->orderBy('sequence')->pluck('code')->all();

    expect(array_slice($codes, 8, 2))->toBe(['ART-5-09', 'ART-7-01']);
});
