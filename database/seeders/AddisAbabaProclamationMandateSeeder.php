<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\LegalSource;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationDesign\FunctionService;
use App\Services\OrganizationDesign\LegalSourceService;
use App\Services\OrganizationDesign\MandateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Puts proclamation 94/2019 into the start of the design chain: the legal source,
 * one mandate per institution, and each institution's powers and functions, one
 * function per numbered item of its article with lettered items as sub-functions.
 * The text is the proclamation's own, in database/seeders/data/proclamation-94-2019-powers.json.
 *
 * Run after AddisAbabaProclamationInstitutionSeeder; not part of DatabaseSeeder.
 * A mandate's description holds the whole text of what the proclamation gives that
 * institution. Mandates stay drafts: publishing needs a second person, and nobody's approval is
 * recorded here. The function's required English title is the article citation
 * until someone translates it. Services, processes and staffing are not in the
 * proclamation and are not created. Idempotent per institution.
 */
class AddisAbabaProclamationMandateSeeder extends Seeder
{
    public const SOURCE_CODE = 'AA-PROC-94-2019';

    public const MANDATE_CODE = 'PROC-94-2019';

    private const CITATION = 'አዋጅ ቁጥር 94/2019';

    /** Bodies the proclamation names without giving them powers of their own to record. */
    private const NO_MANDATE = ['የአዲስ አበባ ከተማ ምክር ቤት', 'የመንግሥት ሠራተኞች የብቃት ምዘናና ማረጋገጫ ተቋም'];

    /** @var array<string, list<int>> Amharic institution name => articles listing its powers. */
    private const ARTICLES = [
        'የአዲስ አበባ ከተማ አስተዳደር' => [5, 7],
        'የከተማ ሥራ አስኪያጅ' => [10],
        'የከንቲባ ጽህፈት ቤት' => [17],
        'የኢንዱስትሪ ልማት ቢሮ' => [18],
        'የሠራተኛና ሥራ ዕድል ፈጠራ ቢሮ' => [19],
        'የፋይናንስ ቢሮ' => [20],
        'የቤቶች ልማትና አስተዳደር ቢሮ' => [21],
        'የቴክኒክና ሙያ ሥልጠና ቢሮ' => [22],
        'የፐብሊክ ሰርቪስና የሰው ሀብት ልማት ቢሮ' => [23],
        'የሰላምና ጸጥታ አስተዳደር ቢሮ' => [24],
        'የባህልና ቱሪዝም ቢሮ' => [25],
        'የፍትህ ቢሮ' => [26],
        'የመሬት ልማትና አስተዳደር ቢሮ' => [27],
        'የትራንስፖርት ቢሮ' => [28],
        'የዲዛይንና ግንባታ ሥራዎች ቢሮ' => [29],
        'የኢኖቬሽንና ቴክኖሎጂ ልማት ቢሮ' => [30],
        'የጤና ቢሮ' => [31],
        'የንግድ ቢሮ' => [32],
        'የትምህርት ቢሮ' => [33],
        'የሴቶች፣ ሕጻናትና ማህበራዊ ጉዳይ ቢሮ' => [34],
        'የገቢዎች ቢሮ' => [35],
        'የወጣቶችና ስፖርት ቢሮ' => [36],
        'የኮሙኒኬሽን ቢሮ' => [37],
        'የከተማ ውበትና አረንጓዴ ልማት ቢሮ' => [38],
        'የፕላንና ልማት ቢሮ' => [39],
        'የኢንቨስትመንት ኮሚሽን' => [40],
        'የግንባታ ፈቃድና ቁጥጥር ባለሥልጣን' => [41],
        'የአርሶ አደርና ከተማ ግብርና ልማት ኮሚሽን' => [42],
        'የህብረተሰብ ተሳትፎና በጎፈቃድ ማስተባበሪያ ኮሚሽን' => [43],
        'የእሳትና አደጋ ስጋት ሥራ አመራር ኮሚሽን' => [44],
        'የህብረት ሥራ ኮሚሽን' => [45],
        'የትምህርትና ስልጠና ጥራት ቁጥጥር ባለሥልጣን' => [46],
        'የምግብና መድሃኒት ባለሥልጣን' => [47],
        'የአሽከርካሪና ተሽከርካሪ ፈቃድና ቁጥጥር ባለሥልጣን' => [48],
        'የደንብ ማስከበር ባለሥልጣን' => [49],
        'የአካባቢ ጥበቃ ባለሥልጣን' => [50],
        'የመንገዶች ባለሥልጣን' => [51],
        'የውሃና ፍሳሽ ባለሥልጣን' => [52],
        'የቤቶች ልማት ኮርፖሬሽን' => [53],
        'የመሬት ይዞታና መሬት ነክ ንብረት ምዝገባና መረጃ ኤጀንሲ' => [54],
        'የጽዳት አስተዳደር ኤጀንሲ' => [55],
        'የሲቪል ምዝገባ እና የነዋሪነት አገልግሎት ኤጀንሲ' => [56],
        'የመንግሥት የግዥና ንብረት አስተዳደር ኤጀንሲ' => [57],
    ];

    /** @var array<int, string> */
    private const ARTICLE_TITLES = [
        5 => 'የከንቲባ ሥልጣንና ተግባር',
        7 => 'የካቢኔ ሥልጣንና ተግባር',
        10 => 'የከተማ ሥራ አስኪያጅ ሥልጣንና ተግባር',
        76 => 'የክፍለ ከተማ አስፈጻሚ አካላት ሥልጣንና ተግባር',
    ];

    public function run(LegalSourceService $sources, MandateService $mandates, FunctionService $functions): void
    {
        $articles = json_decode((string) file_get_contents(__DIR__.'/data/proclamation-94-2019-powers.json'), true, flags: JSON_THROW_ON_ERROR);
        $actor = User::role('Super Admin')->orderBy('id')->first()
            ?? throw new RuntimeException('A Super Admin account must exist before recording mandates.');
        $instrumentType = LegalInstrumentType::query()->where('code', 'PROCLAMATION')->first()
            ?? throw new RuntimeException('The PROCLAMATION legal instrument type must exist; run LegalInstrumentTypeSeeder.');

        DB::transaction(function () use ($articles, $actor, $instrumentType, $sources, $mandates, $functions): void {
            $source = LegalSource::query()->where('code', self::SOURCE_CODE)->first() ?? $sources->create($actor, null, [
                'code' => self::SOURCE_CODE,
                'title_en' => 'Addis Ababa City Administration Executive Bodies Powers and Functions Proclamation No. 94/2019',
                'title_am' => 'የአዲስ አበባ ከተማ አስተዳደር አስፈፃሚ አካላት ሥልጣንና ተግባር መወሰኛ አዋጅ ቁጥር 94/2019',
                'source_type_id' => $instrumentType->getKey(),
                'issuing_authority' => 'የአዲስ አበባ ከተማ ምክር ቤት',
                'document_number' => '94/2019',
                'issue_date' => AddisAbabaProclamationInstitutionSeeder::EFFECTIVE_FROM,
                'effective_from' => AddisAbabaProclamationInstitutionSeeder::EFFECTIVE_FROM,
            ]);

            Organization::query()
                ->where('legal_basis_ref', 'like', self::CITATION.'%')
                ->whereNotIn('name_am', self::NO_MANDATE)
                ->orderBy('code')
                ->get()
                ->each(function (Organization $organization) use ($articles, $actor, $instrumentType, $source, $mandates, $functions): void {
                    $numbers = self::ARTICLES[$organization->name_am]
                        ?? ($organization->type?->name_am === 'ክፍለ ከተማ' ? [76] : []);
                    $description = $this->mandateText($organization, $numbers, $articles);

                    $existing = InstitutionMandate::query()->where('organization_id', $organization->getKey())->where('code', self::MANDATE_CODE)->first();
                    if ($existing !== null) {
                        // Only a draft with nothing written yet: recorded wording and published versions stay as they are.
                        if ($existing->description_am === null && $existing->status->isEditable()) {
                            $mandates->updateDraft($actor, $existing, ['description_am' => $description]);
                        }

                        return;
                    }

                    $articleReference = trim(str_replace(self::CITATION.' አንቀጽ', '', (string) $organization->legal_basis_ref));
                    $mandate = $mandates->createDraft($actor, $organization->getKey(), [
                        'code' => self::MANDATE_CODE,
                        'title_en' => mb_substr('Proclamation 94/2019 mandate: '.$organization->name_en, 0, 255),
                        'title_am' => mb_substr($organization->name_am.' በ'.self::CITATION.' የተሰጠ ሥልጣን', 0, 255),
                        'description_am' => $description,
                        'legal_instrument_type_id' => $instrumentType->getKey(),
                        'legal_instrument_number' => '94/2019',
                        'legal_reference' => $organization->legal_basis_ref,
                        'article_reference' => $articleReference,
                        'issuing_authority' => 'የአዲስ አበባ ከተማ ምክር ቤት',
                        'legal_source_id' => $source->getKey(),
                        'effective_from' => AddisAbabaProclamationInstitutionSeeder::EFFECTIVE_FROM,
                    ]);

                    // Article 16 binds every body article 11 sets up: recorded once as a power, not copied as functions.
                    if (str_contains((string) $organization->legal_basis_ref, '11(')) {
                        $functions->createPower($actor, $mandate, [
                            'code' => 'ART-16',
                            'title_en' => 'Common powers and functions (Proclamation 94/2019, Article 16)',
                            'title_am' => 'የወል ሥልጣንና ተግባር',
                            'description_am' => $this->articleText($articles['16']),
                            'legal_reference' => self::CITATION.' አንቀጽ 16',
                            'sequence' => 1,
                            'effective_from' => AddisAbabaProclamationInstitutionSeeder::EFFECTIVE_FROM,
                            'status' => 'active',
                        ]);
                    }

                    foreach ($numbers as $position => $number) {
                        $this->recordArticle($actor, $functions, $mandate, $number, $articles[(string) $number], $position + 2);
                    }
                });
        });
    }

    /** @param array{intro: string, items: list<array{n: int, text: string, subs: list<array{letter: string, text: string}>}>} $article */
    private function recordArticle(User $actor, FunctionService $functions, InstitutionMandate $mandate, int $number, array $article, int $sequence): void
    {
        $from = AddisAbabaProclamationInstitutionSeeder::EFFECTIVE_FROM;
        $power = $functions->createPower($actor, $mandate, [
            'code' => "ART-{$number}",
            'title_en' => "Powers and functions (Proclamation 94/2019, Article {$number})",
            'title_am' => self::ARTICLE_TITLES[$number] ?? 'የተቋሙ ሥልጣንና ተግባር',
            'description_am' => $article['intro'] === '' ? null : $article['intro'],
            'legal_reference' => self::CITATION." አንቀጽ {$number}",
            'sequence' => $sequence,
            'effective_from' => $from,
            'status' => 'active',
        ]);

        foreach ($article['items'] as $item) {
            $code = sprintf('ART-%d-%02d', $number, $item['n']);
            $parent = $functions->createFunction($actor, $mandate, [
                ...$this->textFields($item['text']),
                'power_id' => $power->getKey(),
                'code' => $code,
                'title_en' => "Proclamation 94/2019, Article {$number}({$item['n']})",
                'legal_reference' => self::CITATION." አንቀጽ {$number}({$item['n']})",
                // Each article keeps its own block, so an institution with two articles reads in article order.
                'sequence' => ($sequence - 2) * 100 + $item['n'],
                'effective_from' => $from,
                'status' => 'active',
            ]);

            foreach ($item['subs'] as $index => $sub) {
                $functions->createFunction($actor, $mandate, [
                    ...$this->textFields($sub['text']),
                    'parent_function_id' => $parent->getKey(),
                    'power_id' => $power->getKey(),
                    'code' => sprintf('%s-%02d', $code, $index + 1),
                    'title_en' => "Proclamation 94/2019, Article {$number}({$item['n']})({$sub['letter']})",
                    'legal_reference' => self::CITATION." አንቀጽ {$number}({$item['n']})({$sub['letter']})",
                    'sequence' => $index + 1,
                    'effective_from' => $from,
                    'status' => 'active',
                ]);
            }
        }
    }

    /** @return array{title_am: string, description_am: string|null} The title holds 255 letters; a longer item keeps its full text in the description. */
    private function textFields(string $text): array
    {
        return mb_strlen($text) <= 255
            ? ['title_am' => $text, 'description_am' => null]
            : ['title_am' => mb_substr($text, 0, 255), 'description_am' => $text];
    }

    /**
     * @param  list<int>  $numbers
     * @param  array<string, mixed>  $articles
     */
    private function mandateText(Organization $organization, array $numbers, array $articles): ?string
    {
        if ($numbers === []) {
            return $articles['legal_only'][$organization->name_am] ?? null;
        }

        $parts = array_map(
            fn (int $number): string => "አንቀጽ {$number}፦ ".$this->articleText($articles[(string) $number]),
            $numbers,
        );
        if (str_contains((string) $organization->legal_basis_ref, '11(')) {
            $parts[] = 'አንቀጽ 16፦ '.$this->articleText($articles['16']);
        }

        return implode("\n\n", $parts);
    }

    /** @param array{intro: string, items: list<array{n: int, text: string, subs: list<array{letter: string, text: string}>}>} $article */
    private function articleText(array $article): string
    {
        $lines = [$article['intro']];
        foreach ($article['items'] as $item) {
            $lines[] = "{$item['n']}. {$item['text']}";
            foreach ($item['subs'] as $sub) {
                $lines[] = "   {$sub['letter']}) {$sub['text']}";
            }
        }

        return implode("\n", $lines);
    }
}
