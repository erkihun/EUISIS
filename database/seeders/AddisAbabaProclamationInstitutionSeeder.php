<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Organizations\CreateOrganizationAction;
use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Actions\OrganizationTypes\CreateOrganizationTypeAction;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Organizations\AmharicOrganizationCodes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registers the institutions named in Addis Ababa proclamation 94/2019 and
 * publishes their reporting structure (articles 11, 12, 59-63, 91 and 93) as one
 * hierarchy version. The rows live in
 * database/seeders/data/addis-ababa-proclamation-94-2019-institutions.csv.
 *
 * Not part of the default seeding: it registers real institutions, so it is run on
 * purpose. Idempotent on the Amharic name: a live institution of that name is
 * reused and a re-run publishes nothing new. It archives nothing. English names
 * are translations; the Amharic names follow the proclamation's text. The 11
 * sub-cities are the exception: article 2(7) sets up the level and article 68
 * their reporting line, but it does not name them, and each row says so.
 */
class AddisAbabaProclamationInstitutionSeeder extends Seeder
{
    public const VERSION_NAME = 'አዋጅ ቁጥር 94/2019';

    /** 20 Meskerem 2019 E.C., the date article 98 brings the proclamation into force. */
    public const EFFECTIVE_FROM = '2026-09-30';

    /** @var array<string, array{name_en: string, name_am: string}> Keyed by the dataset's type_code column. */
    private const TYPES = [
        'CITY_GOVERNMENT' => ['name_en' => 'City Government', 'name_am' => 'ከተማ አስተዳደር'],
        'COUNCIL' => ['name_en' => 'Council', 'name_am' => 'ምክር ቤት'],
        'OFFICE' => ['name_en' => 'Office', 'name_am' => 'ጽህፈት ቤት'],
        'MAYOR_OFFICE' => ['name_en' => 'Mayor Office', 'name_am' => 'የከንቲባ ጽህፈት ቤት'],
        'BUREAU' => ['name_en' => 'Bureau', 'name_am' => 'ቢሮ'],
        'COMMISSION' => ['name_en' => 'Commission', 'name_am' => 'ኮሚሽን'],
        'AUTHORITY' => ['name_en' => 'Authority', 'name_am' => 'ባለሥልጣን'],
        'AGENCY' => ['name_en' => 'Agency', 'name_am' => 'ኤጀንሲ'],
        'CORPORATION' => ['name_en' => 'Corporation', 'name_am' => 'ኮርፖሬሽን'],
        'ENTERPRISE' => ['name_en' => 'Development Enterprise', 'name_am' => 'የልማት ድርጅት'],
        'HOLDINGS' => ['name_en' => 'Holdings', 'name_am' => 'ሆልዲንግስ'],
        'CENTER' => ['name_en' => 'Centre', 'name_am' => 'ማዕከል'],
        'INSTITUTE' => ['name_en' => 'Institute', 'name_am' => 'ተቋም'],
        'SUB_CITY' => ['name_en' => 'Sub-city', 'name_am' => 'ክፍለ ከተማ'],
    ];

    public function run(
        CreateOrganizationAction $createOrganization,
        PublishHierarchyVersionAction $publish,
        CreateOrganizationTypeAction $createType,
        AmharicOrganizationCodes $codes,
    ): void {
        $rows = $this->readDataset(__DIR__.'/data/addis-ababa-proclamation-94-2019-institutions.csv');
        $actor = User::role('Super Admin')->orderBy('id')->first()
            ?? throw new RuntimeException('A Super Admin account must exist before registering institutions.');

        DB::transaction(function () use ($rows, $actor, $createOrganization, $publish, $createType, $codes): void {
            $codes->apply($actor);
            $types = $this->ensureTypes($createType, $actor);

            $version = HierarchyVersion::query()->where('version_name', self::VERSION_NAME)->first();
            // Once published this version is history, even after a later one replaces it.
            if ($version !== null && $version->status !== HierarchyVersionStatus::Draft) {
                return;
            }
            // Built directly: the create action copies the published tree into a new
            // draft, and this version replaces that tree instead of changing it.
            $version ??= HierarchyVersion::query()->create([
                'version_name' => self::VERSION_NAME,
                'source_document' => 'የአዲስ አበባ ከተማ አስተዳደር አስፈፃሚ አካላት ሥልጣንና ተግባር መወሰኛ አዋጅ ቁጥር 94/2019',
                'status' => HierarchyVersionStatus::Draft,
                'effective_from' => self::EFFECTIVE_FROM,
            ]);

            $idsByKey = [];
            foreach ($rows as $position => $row) {
                $parentId = $row['parent_key'] === '' ? null : ($idsByKey[$row['parent_key']]
                    ?? throw new RuntimeException("Parent {$row['parent_key']} of {$row['key']} must appear before it."));

                $organization = Organization::query()->where('name_am', $row['name_am'])->first()
                    ?? $createOrganization->execute([
                        'organization_type_id' => $types[$row['type_code']],
                        'name_en' => $row['name_en'],
                        'name_am' => $row['name_am'],
                        'status' => $row['status'],
                        'legal_basis_ref' => 'አዋጅ ቁጥር 94/2019 አንቀጽ '.$row['article'],
                        'effective_from' => self::EFFECTIVE_FROM,
                        'metadata' => $row['note'] === '' ? null : ['legal_note' => $row['note']],
                        'parent_organization_id' => $parentId,
                        'hierarchy_version_id' => $version->getKey(),
                        'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
                    ], $actor);

                $idsByKey[$row['key']] = $organization->getKey();

                // The list starts in the order of the dataset; a position chosen by hand stays.
                Organization::query()->whereKey($organization->getKey())->whereNull('sort_order')->update(['sort_order' => $position + 1]);

                // An institution that was already registered gets its place in this version too.
                if ($parentId !== null) {
                    OrganizationEdge::query()->firstOrCreate([
                        'hierarchy_version_id' => $version->getKey(),
                        'child_organization_id' => $organization->getKey(),
                    ], [
                        'parent_organization_id' => $parentId,
                        'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
                        'effective_from' => self::EFFECTIVE_FROM,
                    ]);
                }
            }

            $publish->execute($version->fresh(), $actor);
        });
    }

    /** @return array<string, string> Type ids by the dataset's type_code; a type's own code is generated. */
    private function ensureTypes(CreateOrganizationTypeAction $createType, User $actor): array
    {
        $ids = [];
        foreach (self::TYPES as $key => $type) {
            $model = OrganizationType::query()->where('name_am', $type['name_am'])->first()
                ?? $createType->execute($type, $actor);
            $ids[$key] = (string) $model->getKey();
        }

        return $ids;
    }

    /**
     * @return list<array{key: string, parent_key: string, type_code: string, status: string, article: string, name_en: string, name_am: string, note: string}>
     */
    private function readDataset(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read the institutions dataset at {$path}.");
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        $expected = ['key', 'parent_key', 'type_code', 'status', 'article', 'name_en', 'name_am', 'note'];
        if ($header !== $expected) {
            fclose($handle);
            throw new RuntimeException('The institutions dataset must start with the columns '.implode(',', $expected).'.');
        }

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($line) === count($expected)) {
                $rows[] = array_combine($expected, array_map(fn ($value) => trim((string) $value), $line));
            }
        }
        fclose($handle);

        return $rows;
    }
}
