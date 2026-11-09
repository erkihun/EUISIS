<?php

declare(strict_types=1);

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\User;
use Database\Seeders\AddisAbabaProclamationInstitutionSeeder as InstitutionSeeder;
use Database\Seeders\CodeRuleSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(CodeRuleSeeder::class);
    Role::findOrCreate('Super Admin', 'web');
    User::factory()->create()->assignRole('Super Admin');
});

function proclamationParent(string $nameAm): ?string
{
    $child = Organization::query()->where('name_am', $nameAm)->firstOrFail();
    $parentId = OrganizationEdge::query()->where('child_organization_id', $child->getKey())->value('parent_organization_id');

    return $parentId === null ? null : Organization::query()->whereKey($parentId)->value('name_am');
}

test('the proclamation seeder registers every institution under the body it reports to and publishes the structure', function (): void {
    $this->seed(InstitutionSeeder::class);

    $root = 'የአዲስ አበባ ከተማ አስተዳደር';
    $version = HierarchyVersion::query()->where('version_name', InstitutionSeeder::VERSION_NAME)->firstOrFail();

    expect(Organization::query()->count())->toBe(71)
        ->and(OrganizationEdge::query()->where('hierarchy_version_id', $version->getKey())->count())->toBe(70)
        ->and($version->status)->toBe(HierarchyVersionStatus::Published)
        ->and(Organization::query()->whereNull('name_am')->count())->toBe(0)
        ->and(Organization::query()->whereNull('name_en')->count())->toBe(0)
        ->and(proclamationParent($root))->toBeNull()
        ->and(proclamationParent('የጤና ቢሮ'))->toBe($root)
        ->and(proclamationParent('ቦሌ ክፍለ ከተማ'))->toBe($root)
        ->and(HierarchyVersion::query()->count())->toBe(1)
        ->and(proclamationParent('የእሳትና አደጋ ስጋት ሥራ አመራር ኮሚሽን'))->toBe('የከተማ ሥራ አስኪያጅ')
        ->and(proclamationParent('የደንብ ማስከበር ባለሥልጣን'))->toBe('የሰላምና ጸጥታ አስተዳደር ቢሮ')
        ->and(proclamationParent('የቤቶች ልማት ኮርፖሬሽን'))->toBe('የቤቶች ልማትና አስተዳደር ቢሮ')
        ->and(proclamationParent('የመንግሥት የግዥና ንብረት አስተዳደር ኤጀንሲ'))->toBe('የፋይናንስ ቢሮ')
        ->and(proclamationParent('ጉለሌ የዕፅዋት ማዕከል'))->toBe('የከተማ ውበትና አረንጓዴ ልማት ቢሮ')
        ->and(proclamationParent('የገላን ጉራ የኢንዱስትሪ ፓርክ ኮርፖሬሽን'))->toBe('የኢንዱስትሪ ልማት ቢሮ')
        ->and(proclamationParent('የከተማ አውቶቡስ አገልግሎት ድርጅት'))->toBe('የትራንስፖርት ቢሮ');

    expect(Organization::query()->orderBy('sort_order')->limit(2)->pluck('name_am')->all())
        ->toBe([$root, 'የአዲስ አበባ ከተማ ምክር ቤት'])
        ->and(Organization::query()->whereNull('sort_order')->count())->toBe(0);

    $health = Organization::query()->where('name_am', 'የጤና ቢሮ')->firstOrFail();
    expect($health->code)->toMatch('/^ቢ-\d{2}$/u')
        ->and($health->legal_basis_ref)->toBe('አዋጅ ቁጥር 94/2019 አንቀጽ 11(15), 31')
        ->and($health->effective_from?->toDateString())->toBe(InstitutionSeeder::EFFECTIVE_FROM);
});

test('re-running the proclamation seeder registers and publishes nothing new', function (): void {
    $this->seed(InstitutionSeeder::class);
    $this->seed(InstitutionSeeder::class);

    expect(Organization::query()->count())->toBe(71)
        ->and(HierarchyVersion::query()->where('version_name', InstitutionSeeder::VERSION_NAME)->count())->toBe(1);
});

test('a body the proclamation only says will be established stays a draft with the reason on record', function (): void {
    $this->seed(InstitutionSeeder::class);

    $centre = Organization::query()->where('name_am', 'የትራንስፖርት ማኔጅመንት ማዕከል')->firstOrFail();

    expect($centre->status->value)->toBe('draft')
        ->and($centre->metadata['legal_note'] ?? null)->not->toBeNull();
});
