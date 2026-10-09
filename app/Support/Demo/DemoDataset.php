<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceNetwork;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The deterministic development / QA / UAT demo dataset (docs/demo-seed-data.md).
 *
 * Synthetic data only — never production master data. Records the code-rule
 * engine numbers (organizations, units, positions, employees, cards) cannot
 * be found again by their code, so each carries a stable natural key in
 * `metadata.demo_key`; the rest use a DEMO-prefixed code or email. Demo rows
 * do not set `is_demo`: DatabaseSeeder purges `is_demo` rows it owns, and
 * these must survive a normal `db:seed`.
 */
final class DemoDataset
{
    public const TAG = 'euisis-demo-v1';

    public const EMAIL_DOMAIN = 'example.test';

    public const ROOT = 'ROOT';

    public const HIERARCHY_VERSION_NAME = 'DEMO Hierarchy v1';

    /** Users that record the seeder's actions: maker, and the second person for maker-checker steps. */
    public const MAKER_EMAIL = 'demo.city.admin@example.test';

    public const CHECKER_EMAIL = 'demo.cafeteria.admin@example.test';

    public const CARD_APPROVER_EMAIL = 'demo.idcard.approver@example.test';

    /** @return array<string, mixed> */
    public static function tag(string $key, array $extra = []): array
    {
        return ['demo_dataset' => self::TAG, 'demo_key' => $key, ...$extra];
    }

    /**
     * The dataset's anchor date: the root organization's start date once it
     * exists, so every later run reuses the dates of the first one.
     */
    public static function epoch(): Carbon
    {
        $root = self::organization(self::ROOT);

        return $root?->effective_from?->copy()->startOfDay() ?? self::anchor()->subYear()->startOfMonth();
    }

    /**
     * The day of the first run, kept on the root organization. Relative dates
     * (historical scans, Organization 5's policy v2) count back from it, so a
     * later run never adds new "recent" rows.
     */
    public static function anchor(): Carbon
    {
        $seededOn = self::organization(self::ROOT)?->metadata['seeded_on'] ?? null;

        return is_string($seededOn) ? Carbon::parse($seededOn)->startOfDay() : today();
    }

    // ── Catalog ──────────────────────────────────────────────────────────────

    /**
     * The demo root (a structural support record) and the five business
     * organizations. Parent types follow OrganizationType::parent_allowed_types.
     *
     * @return array<string, array{type: string, parent: string|null, name_en: string, name_am: string, purpose: string}>
     */
    public static function organizations(): array
    {
        return [
            self::ROOT => ['type' => 'CITY_ADMIN', 'parent' => null, 'name_en' => 'DEMO City Administration', 'name_am' => 'ማሳያ የከተማ አስተዳደር', 'purpose' => 'Hierarchy root for the demo organizations (support record).'],
            'ORG-1' => ['type' => 'BUREAU', 'parent' => self::ROOT, 'name_en' => 'DEMO Civic Services Bureau', 'name_am' => 'ማሳያ የሲቪል አገልግሎት ቢሮ', 'purpose' => 'Basic HR and cafeteria happy path.'],
            'ORG-2' => ['type' => 'AUTHORITY', 'parent' => self::ROOT, 'name_en' => 'DEMO Urban Works Authority', 'name_am' => 'ማሳያ የከተማ ሥራዎች ባለሥልጣን', 'purpose' => 'Cross-location (main / branch) cafeteria usage.'],
            'ORG-3' => ['type' => 'COMMISSION', 'parent' => self::ROOT, 'name_en' => 'DEMO Public Health Commission', 'name_am' => 'ማሳያ የሕዝብ ጤና ኮሚሽን', 'purpose' => 'Different cafeteria working-day and advance-use policy.'],
            'ORG-4' => ['type' => 'BUREAU', 'parent' => self::ROOT, 'name_en' => 'DEMO Planning and Development Bureau', 'name_am' => 'ማሳያ የዕቅድና ልማት ቢሮ', 'purpose' => 'Subtree scope, several internal units, management testing.'],
            'ORG-5' => ['type' => 'AGENCY', 'parent' => 'ORG-4', 'name_en' => 'DEMO Records and Archives Agency', 'name_am' => 'ማሳያ የመዛግብትና ቤተ መዛግብት ኤጀንሲ', 'purpose' => 'Policy version history and optional workflow modules.'],
        ];
    }

    /** @return list<string> The five business organizations (the root excluded). */
    public static function businessOrganizationKeys(): array
    {
        return array_values(array_diff(array_keys(self::organizations()), [self::ROOT]));
    }

    /** @return array{type: string, parent: string|null, name_en: string, name_am: string, purpose: string} */
    public static function organizationDefinition(string $key): array
    {
        return self::organizations()[$key] ?? throw new RuntimeException("Unknown demo organization {$key}.");
    }

    /**
     * Units of one organization, parents first. Unit type codes are those of
     * OrganizationUnitTypeSeeder.
     *
     * @return array<string, array{type: string, parent: string|null, name_en: string, name_am: string}>
     */
    public static function units(string $organizationKey): array
    {
        $units = [
            'HEAD' => ['type' => 'office', 'parent' => null, 'name_en' => 'Office of the Head', 'name_am' => 'የኃላፊ ጽ/ቤት'],
            'HR' => ['type' => 'directorate', 'parent' => 'HEAD', 'name_en' => 'Human Resource Directorate', 'name_am' => 'የሰው ሀብት ዳይሬክቶሬት'],
            'FIN' => ['type' => 'directorate', 'parent' => 'HEAD', 'name_en' => 'Finance Directorate', 'name_am' => 'የፋይናንስ ዳይሬክቶሬት'],
            'ICT' => ['type' => 'directorate', 'parent' => 'HEAD', 'name_en' => 'Information Technology Directorate', 'name_am' => 'የኢንፎርሜሽን ቴክኖሎጂ ዳይሬክቶሬት'],
            'PLAN' => ['type' => 'directorate', 'parent' => 'HEAD', 'name_en' => 'Planning and Performance Directorate', 'name_am' => 'የዕቅድና አፈጻጸም ዳይሬክቶሬት'],
            'SVC' => ['type' => 'directorate', 'parent' => 'HEAD', 'name_en' => 'Service Delivery Directorate', 'name_am' => 'የአገልግሎት አሰጣጥ ዳይሬክቶሬት'],
        ];

        if ($organizationKey === 'ORG-4') {
            $units['HR-REC'] = ['type' => 'team', 'parent' => 'HR', 'name_en' => 'Recruitment Team', 'name_am' => 'የቅጥር ቡድን'];
            $units['SVC-CS'] = ['type' => 'team', 'parent' => 'SVC', 'name_en' => 'Customer Service Team', 'name_am' => 'የደንበኞች አገልግሎት ቡድን'];
        }

        return $units;
    }

    /**
     * A functional (dotted-line) relationship next to the structural parent:
     * source unit reports functionally to the target unit.
     *
     * @return array{source: string, target: string}
     */
    public static function functionalRelationship(): array
    {
        return ['source' => 'SVC', 'target' => 'PLAN'];
    }

    /**
     * Positions of one organization. `isco` picks an occupation seeded by
     * OccupationSeeder; `vacant` positions get no employee.
     *
     * @return array<string, array{unit: string, title_en: string, title_am: string, isco: string, manager: bool, vacant: bool}>
     */
    public static function positions(string $organizationKey): array
    {
        $positions = [
            'P-HEAD' => ['unit' => 'HEAD', 'title_en' => 'Head of Organization', 'title_am' => 'የተቋሙ ኃላፊ', 'isco' => '3353', 'manager' => true, 'vacant' => false],
            'P-HR' => ['unit' => 'HR', 'title_en' => 'Human Resource Officer', 'title_am' => 'የሰው ሀብት ባለሙያ', 'isco' => '2423', 'manager' => false, 'vacant' => false],
            'P-FIN' => ['unit' => 'FIN', 'title_en' => 'Finance Officer', 'title_am' => 'የፋይናንስ ባለሙያ', 'isco' => '2411', 'manager' => false, 'vacant' => false],
            'P-ICT' => ['unit' => 'ICT', 'title_en' => 'ICT Officer', 'title_am' => 'የአይሲቲ ባለሙያ', 'isco' => '2512', 'manager' => false, 'vacant' => false],
            'P-PLAN' => ['unit' => 'PLAN', 'title_en' => 'Planning and Performance Officer', 'title_am' => 'የዕቅድና አፈጻጸም ባለሙያ', 'isco' => '3353', 'manager' => false, 'vacant' => false],
            'P-SVC' => ['unit' => 'SVC', 'title_en' => 'Service Delivery Officer', 'title_am' => 'የአገልግሎት አሰጣጥ ባለሙያ', 'isco' => '3353', 'manager' => false, 'vacant' => false],
            'P-CLERK' => ['unit' => 'SVC', 'title_en' => 'Records Clerk', 'title_am' => 'የመዛግብት ጸሐፊ', 'isco' => '4110', 'manager' => false, 'vacant' => true],
        ];

        if ($organizationKey === 'ORG-4') {
            $positions['P-HR-MGR'] = ['unit' => 'HR', 'title_en' => 'Human Resource Director', 'title_am' => 'የሰው ሀብት ዳይሬክተር', 'isco' => '2423', 'manager' => true, 'vacant' => false];
            $positions['P-RECRUIT'] = ['unit' => 'HR-REC', 'title_en' => 'Recruitment Officer', 'title_am' => 'የቅጥር ባለሙያ', 'isco' => '2423', 'manager' => false, 'vacant' => false];
            $positions['P-CS'] = ['unit' => 'SVC-CS', 'title_en' => 'Customer Service Officer', 'title_am' => 'የደንበኞች አገልግሎት ባለሙያ', 'isco' => '4110', 'manager' => false, 'vacant' => true];
        }

        return $positions;
    }

    /**
     * One employee per occupied position. Names are synthetic: Amharic in
     * first/middle/last (as the ID card reads full_name), English in name_en.
     * National IDs start with ten zeros — valid format, never a real ID.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function employees(): array
    {
        $male = [['አበበ', 'Abebe'], ['ዳዊት', 'Dawit'], ['ዮናስ', 'Yonas'], ['ሳሙኤል', 'Samuel'], ['ተስፋዬ', 'Tesfaye'], ['በረከት', 'Bereket'], ['ሄኖክ', 'Henok'], ['ካሌብ', 'Kaleb']];
        $female = [['ሰላም', 'Selam'], ['ሐና', 'Hana'], ['ሜሮን', 'Meron'], ['ትዕግስት', 'Tigist'], ['ሊያ', 'Liya'], ['ቤተልሔም', 'Bethlehem'], ['ሩት', 'Ruth'], ['ሳሮን', 'Saron']];
        $fathers = [['አለሙ', 'Alemu'], ['በቀለ', 'Bekele'], ['ግርማ', 'Girma'], ['ኃይሌ', 'Haile'], ['ከበደ', 'Kebede'], ['ሙሉጌታ', 'Mulugeta'], ['ታደሰ', 'Tadesse'], ['ወርቁ', 'Worku']];
        $grandfathers = [['አሰፋ', 'Assefa'], ['ደምሴ', 'Demissie'], ['ገብሬ', 'Gebre'], ['መኮንን', 'Mekonnen'], ['ነጋሽ', 'Negash'], ['ፀጋዬ', 'Tsegaye'], ['ወልዴ', 'Wolde'], ['ይርጋ', 'Yirga']];

        $employees = [];
        $i = 0;
        foreach (self::businessOrganizationKeys() as $organizationIndex => $organizationKey) {
            $n = 0;
            foreach (self::positions($organizationKey) as $positionKey => $position) {
                if ($position['vacant']) {
                    continue;
                }
                $n++;
                $isFemale = $i % 2 === 1;
                $first = ($isFemale ? $female : $male)[intdiv($i, 2) % 8];
                $father = $fathers[$i % 8];
                $grandfather = $grandfathers[(intdiv($i, 8) + $i) % 8];
                $organizationNumber = $organizationIndex + 1;

                $employees["E-{$organizationNumber}-{$n}"] = [
                    'organization' => $organizationKey,
                    'position' => $positionKey,
                    'first_name' => $first[0],
                    'middle_name' => $father[0],
                    'last_name' => $grandfather[0],
                    'name_en' => "{$first[1]} {$father[1]} {$grandfather[1]}",
                    'email' => strtolower("{$first[1]}.{$father[1]}.o{$organizationNumber}@".self::EMAIL_DOMAIN),
                    'gender' => $isFemale ? 'female' : 'male',
                    'date_of_birth' => sprintf('%d-%02d-%02d', 1980 + ($i * 7) % 16, $i % 12 + 1, $i % 27 + 1),
                    'employment_type' => $i % 5 === 4 ? 'contract' : 'permanent',
                    'national_id' => sprintf('0000000000%02d%04d', $organizationNumber, $n),
                ];
                $i++;
            }
        }

        return $employees;
    }

    /** @return array<string, array{code: string, name_en: string, name_am: string}> */
    public static function providers(): array
    {
        return [
            'PRV-A' => ['code' => 'DEMO-PRV-A', 'name_en' => 'DEMO Catering Provider A', 'name_am' => 'ማሳያ የምግብ አቅራቢ ሀ'],
            'PRV-B' => ['code' => 'DEMO-PRV-B', 'name_en' => 'DEMO Catering Provider B', 'name_am' => 'ማሳያ የምግብ አቅራቢ ለ'],
            'PRV-C' => ['code' => 'DEMO-PRV-C', 'name_en' => 'DEMO Catering Provider C', 'name_am' => 'ማሳያ የምግብ አቅራቢ ሐ'],
        ];
    }

    /**
     * Networks and their five locations each: one main, three branches, one
     * service point.
     *
     * @return array<string, array{provider: string, code: string, name_en: string, name_am: string}>
     */
    public static function networks(): array
    {
        return [
            'A1' => ['provider' => 'PRV-A', 'code' => 'DEMO-NET-A1', 'name_en' => 'DEMO Network A1 (Central)', 'name_am' => 'ማሳያ ኔትወርክ A1 (ማዕከላዊ)'],
            'A2' => ['provider' => 'PRV-A', 'code' => 'DEMO-NET-A2', 'name_en' => 'DEMO Network A2 (East)', 'name_am' => 'ማሳያ ኔትወርክ A2 (ምሥራቅ)'],
            'B1' => ['provider' => 'PRV-B', 'code' => 'DEMO-NET-B1', 'name_en' => 'DEMO Network B1 (North)', 'name_am' => 'ማሳያ ኔትወርክ B1 (ሰሜን)'],
            'B2' => ['provider' => 'PRV-B', 'code' => 'DEMO-NET-B2', 'name_en' => 'DEMO Network B2 (West)', 'name_am' => 'ማሳያ ኔትወርክ B2 (ምዕራብ)'],
            'C1' => ['provider' => 'PRV-C', 'code' => 'DEMO-NET-C1', 'name_en' => 'DEMO Network C1 (South)', 'name_am' => 'ማሳያ ኔትወርክ C1 (ደቡብ)'],
        ];
    }

    /** @return array<string, array{type: string, name_en: string, name_am: string}> keyed by location suffix */
    public static function locations(string $networkKey): array
    {
        return [
            'MAIN' => ['type' => 'main', 'name_en' => "DEMO {$networkKey} Main Cafeteria", 'name_am' => "ማሳያ {$networkKey} ዋና ካፍቴሪያ"],
            'BR1' => ['type' => 'branch', 'name_en' => "DEMO {$networkKey} Branch 1", 'name_am' => "ማሳያ {$networkKey} ቅርንጫፍ 1"],
            'BR2' => ['type' => 'branch', 'name_en' => "DEMO {$networkKey} Branch 2", 'name_am' => "ማሳያ {$networkKey} ቅርንጫፍ 2"],
            'BR3' => ['type' => 'branch', 'name_en' => "DEMO {$networkKey} Branch 3", 'name_am' => "ማሳያ {$networkKey} ቅርንጫፍ 3"],
            'SP1' => ['type' => 'service_point', 'name_en' => "DEMO {$networkKey} Service Point", 'name_am' => "ማሳያ {$networkKey} የአገልግሎት መስጫ"],
        ];
    }

    public static function cafeteriaCode(string $networkKey, string $location): string
    {
        return "DEMO-{$networkKey}-{$location}";
    }

    /**
     * Organization access to networks. Access is explicit per organization;
     * `exceptions` are location-level overrides.
     *
     * @return array<string, list<array{network: string, primary: string, cross_location: bool, exceptions: array<string, bool>}>>
     */
    public static function access(): array
    {
        return [
            'ORG-1' => [['network' => 'A1', 'primary' => 'MAIN', 'cross_location' => true, 'exceptions' => []]],
            // Primary is Branch 1, cross-location ON: may eat at the A1 main
            // cafeteria that Organization 1 uses, on Organization 2's policy.
            'ORG-2' => [
                ['network' => 'A1', 'primary' => 'BR1', 'cross_location' => true, 'exceptions' => []],
                // Cross-location OFF: only the primary and the one allowed exception.
                ['network' => 'A2', 'primary' => 'MAIN', 'cross_location' => false, 'exceptions' => ['BR1' => true]],
            ],
            'ORG-3' => [['network' => 'B1', 'primary' => 'MAIN', 'cross_location' => true, 'exceptions' => []]],
            'ORG-4' => [['network' => 'B2', 'primary' => 'MAIN', 'cross_location' => true, 'exceptions' => []]],
            'ORG-5' => [['network' => 'C1', 'primary' => 'MAIN', 'cross_location' => true, 'exceptions' => []]],
        ];
    }

    /**
     * Service assignments: provider-wide (network null) or one network.
     *
     * @return array<string, array{provider: string, network: string|null}>
     */
    public static function serviceAssignments(): array
    {
        return [
            'ORG-1' => ['provider' => 'PRV-A', 'network' => 'A1'],
            'ORG-2' => ['provider' => 'PRV-A', 'network' => null],
            'ORG-3' => ['provider' => 'PRV-B', 'network' => 'B1'],
            'ORG-4' => ['provider' => 'PRV-B', 'network' => 'B2'],
            'ORG-5' => ['provider' => 'PRV-C', 'network' => 'C1'],
        ];
    }

    /**
     * Organization-specific policy terms. SYNTHETIC DEMO VALUES — not official
     * policy. provider_price = subsidy + contribution, as the workflow enforces.
     * Organization 5 has two versions: v1 (superseded) and v2 (current).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function policies(): array
    {
        $weekdays = ['monday_enabled' => true, 'tuesday_enabled' => true, 'wednesday_enabled' => true, 'thursday_enabled' => true, 'friday_enabled' => true, 'saturday_enabled' => false, 'sunday_enabled' => false];
        $base = ['currency_code' => 'ETB', 'max_daily_uses' => 1, 'exclude_public_holidays' => true, 'block_employee_leave' => true, ...$weekdays];

        $priced = static fn (array $terms): array => [
            ...$terms,
            'provider_price' => sprintf('%.2f', (round((float) $terms['daily_subsidy_amount'] * 100) + round((float) $terms['employee_contribution_amount'] * 100)) / 100),
        ];

        return array_map(static fn (array $versions): array => array_map($priced, $versions), [
            'ORG-1' => [[...$base, 'daily_subsidy_amount' => '100.00', 'employee_contribution_amount' => '0.00', 'allow_advance_usage' => true, 'advance_max_days' => null, 'extra_scan_policy' => 'block']],
            'ORG-2' => [[...$base, 'daily_subsidy_amount' => '120.00', 'employee_contribution_amount' => '10.00', 'allow_advance_usage' => true, 'advance_max_days' => null, 'extra_scan_policy' => 'block', 'saturday_enabled' => true]],
            'ORG-3' => [[...$base, 'daily_subsidy_amount' => '140.00', 'employee_contribution_amount' => '0.00', 'allow_advance_usage' => false, 'advance_max_days' => null, 'extra_scan_policy' => 'employee_paid']],
            'ORG-4' => [[...$base, 'daily_subsidy_amount' => '160.00', 'employee_contribution_amount' => '20.00', 'allow_advance_usage' => true, 'advance_max_days' => 2, 'extra_scan_policy' => 'block']],
            'ORG-5' => [
                [...$base, 'daily_subsidy_amount' => '170.00', 'employee_contribution_amount' => '0.00', 'allow_advance_usage' => true, 'advance_max_days' => null, 'extra_scan_policy' => 'block'],
                [...$base, 'daily_subsidy_amount' => '180.00', 'employee_contribution_amount' => '0.00', 'allow_advance_usage' => true, 'advance_max_days' => null, 'extra_scan_policy' => 'block'],
            ],
        ]);
    }

    /** Days before the first run that Organization 5's policy v2 starts. */
    public const POLICY_V2_DAYS_AGO = 14;

    /**
     * Historical scans: employee, cafeteria, and how many past working days
     * (most recent first). `window` = v1 places them before Organization 5's v2.
     *
     * @return list<array{employee: string, network: string, location: string, days: int, window?: string}>
     */
    public static function transactions(): array
    {
        return [
            ['employee' => 'E-1-2', 'network' => 'A1', 'location' => 'MAIN', 'days' => 2],
            ['employee' => 'E-1-3', 'network' => 'A1', 'location' => 'MAIN', 'days' => 2],
            ['employee' => 'E-1-4', 'network' => 'A1', 'location' => 'BR2', 'days' => 2],
            // Cross-location: Organization 2 staff at Organization 1's usual main cafeteria.
            ['employee' => 'E-2-2', 'network' => 'A1', 'location' => 'MAIN', 'days' => 2],
            ['employee' => 'E-2-3', 'network' => 'A1', 'location' => 'BR1', 'days' => 2],
            // Location exception on a cross-location-OFF access.
            ['employee' => 'E-2-4', 'network' => 'A2', 'location' => 'BR1', 'days' => 1],
            ['employee' => 'E-3-2', 'network' => 'B1', 'location' => 'MAIN', 'days' => 2],
            ['employee' => 'E-3-3', 'network' => 'B1', 'location' => 'BR2', 'days' => 2],
            ['employee' => 'E-4-2', 'network' => 'B2', 'location' => 'MAIN', 'days' => 2],
            ['employee' => 'E-4-3', 'network' => 'B2', 'location' => 'SP1', 'days' => 2],
            ['employee' => 'E-5-2', 'network' => 'C1', 'location' => 'MAIN', 'days' => 2, 'window' => 'v1'],
            ['employee' => 'E-5-2', 'network' => 'C1', 'location' => 'MAIN', 'days' => 2],
            ['employee' => 'E-5-3', 'network' => 'C1', 'location' => 'BR1', 'days' => 2, 'window' => 'v1'],
            ['employee' => 'E-5-3', 'network' => 'C1', 'location' => 'BR1', 'days' => 2],
        ];
    }

    /**
     * ID card examples beyond the normal active card. Most employees keep an
     * active card so normal flows work.
     *
     * @return array<string, string> employee key => lost | expired | reprint_required
     */
    public static function cardExceptions(): array
    {
        return ['E-2-6' => 'lost', 'E-3-6' => 'expired', 'E-4-6' => 'reprint_required'];
    }

    /** Employee on an approved leave covering today (cafeteria leave blocking). */
    public const LEAVE_EMPLOYEE = 'E-1-5';

    /**
     * Staff and employee-portal accounts. Roles come from the default role
     * matrix. `scopes` = [scope type, organization key]; the root key with
     * `subtree` covers the whole demo tree once the hierarchy is published.
     * `employee` links a portal account to its employee record.
     *
     * @return array<string, array{name: string, roles: list<string>, scopes: list<array{0: string, 1: string}>, employee?: string}>
     */
    public static function users(): array
    {
        return [
            self::MAKER_EMAIL => ['name' => 'DEMO City Admin', 'roles' => ['City Admin'], 'scopes' => [['citywide', self::ROOT]]],
            self::CHECKER_EMAIL => ['name' => 'DEMO Cafeteria Admin', 'roles' => ['Cafeteria Admin'], 'scopes' => [['subtree', self::ROOT]]],
            self::CARD_APPROVER_EMAIL => ['name' => 'DEMO ID Card Approver', 'roles' => ['ID Card Approver'], 'scopes' => [['subtree', self::ROOT]]],
            'demo.org1.admin@example.test' => ['name' => 'DEMO Organizational Admin (Org 1)', 'roles' => ['Organizational Admin'], 'scopes' => [['self', 'ORG-1']]],
            'demo.org4.subtree.admin@example.test' => ['name' => 'DEMO Organizational Admin (Org 4 subtree)', 'roles' => ['Organizational Admin'], 'scopes' => [['subtree', 'ORG-4']]],
            'demo.org1.hr@example.test' => ['name' => 'DEMO HR Officer (Org 1)', 'roles' => ['HR Officer'], 'scopes' => [['self', 'ORG-1']]],
            'demo.idcard.officer@example.test' => ['name' => 'DEMO ID Card Officer (Org 1 and 2)', 'roles' => ['ID Card Officer'], 'scopes' => [['self', 'ORG-1'], ['self', 'ORG-2']]],
            // Employee portal: a manager (also the Daily Activity reviewer) and an ordinary employee.
            'demo.org1.manager@example.test' => ['name' => 'DEMO Unit Manager (Org 1 head)', 'roles' => ['Employee', 'Daily Activity Reviewer'], 'scopes' => [['self', 'ORG-1']], 'employee' => 'E-1-1'],
            'demo.org1.employee@example.test' => ['name' => 'DEMO Employee (Org 1)', 'roles' => ['Employee'], 'scopes' => [], 'employee' => 'E-1-6'],
            // Grievance Management (Organization 5): configuration, intake, the committee, a complainant.
            'demo.grievance.admin@example.test' => ['name' => 'DEMO Grievance Administrator (Org 5)', 'roles' => ['Grievance Administrator'], 'scopes' => [['self', 'ORG-5']]],
            'demo.grievance.officer@example.test' => ['name' => 'DEMO Grievance Officer (Org 5)', 'roles' => ['Grievance Officer'], 'scopes' => [['self', 'ORG-5']]],
            'demo.org5.chair@example.test' => ['name' => 'DEMO Committee Chairperson (Org 5)', 'roles' => ['Employee', 'Grievance Committee Chairperson'], 'scopes' => [], 'employee' => 'E-5-2'],
            'demo.org5.writer@example.test' => ['name' => 'DEMO Committee Writer (Org 5)', 'roles' => ['Employee', 'Grievance Committee Writer'], 'scopes' => [], 'employee' => 'E-5-3'],
            'demo.org5.member@example.test' => ['name' => 'DEMO Committee Member (Org 5)', 'roles' => ['Employee', 'Grievance Committee Member'], 'scopes' => [], 'employee' => 'E-5-4'],
            'demo.org5.employee@example.test' => ['name' => 'DEMO Employee (Org 5, complainant)', 'roles' => ['Employee'], 'scopes' => [], 'employee' => 'E-5-6'],
        ];
    }

    /** @return array<string, array{provider: string, name: string, username: string, role: string}> */
    public static function providerUsers(): array
    {
        return [
            'demo.provider.a.owner@example.test' => ['provider' => 'PRV-A', 'name' => 'DEMO Provider A Admin', 'username' => 'demo.prv.a.owner', 'role' => 'owner'],
            'demo.provider.a.manager@example.test' => ['provider' => 'PRV-A', 'name' => 'DEMO Provider A Cafeteria Manager', 'username' => 'demo.prv.a.manager', 'role' => 'manager'],
            'demo.provider.a.operator@example.test' => ['provider' => 'PRV-A', 'name' => 'DEMO Provider A Scanner', 'username' => 'demo.prv.a.operator', 'role' => 'operator'],
            'demo.provider.b.operator@example.test' => ['provider' => 'PRV-B', 'name' => 'DEMO Provider B Scanner', 'username' => 'demo.prv.b.operator', 'role' => 'operator'],
        ];
    }

    // ── Lookups by natural key ───────────────────────────────────────────────

    public static function organization(string $key): ?Organization
    {
        return Organization::query()->where('metadata->demo_key', $key)->where('metadata->demo_dataset', self::TAG)->first();
    }

    public static function requireOrganization(string $key): Organization
    {
        return self::organization($key) ?? throw new RuntimeException("Demo organization {$key} is missing; run the demo seeder from the start.");
    }

    public static function unit(Organization $organization, string $key): ?OrganizationUnit
    {
        return OrganizationUnit::query()->where('organization_id', $organization->id)->where('metadata->demo_key', $key)->first();
    }

    public static function position(Organization $organization, string $key): ?Position
    {
        return Position::query()->where('organization_id', $organization->id)->where('metadata->demo_key', $key)->first();
    }

    public static function employee(string $key): ?Employee
    {
        return Employee::query()->where('metadata->demo_key', $key)->where('metadata->demo_dataset', self::TAG)->first();
    }

    public static function requireEmployee(string $key): Employee
    {
        return self::employee($key) ?? throw new RuntimeException("Demo employee {$key} is missing; run the demo seeder from the start.");
    }

    public static function user(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public static function requireUser(string $email): User
    {
        return self::user($email) ?? throw new RuntimeException("Demo user {$email} is missing; run the demo seeder from the start.");
    }

    public static function provider(string $key): ?Provider
    {
        return Provider::query()->where('provider_code', self::providers()[$key]['code'])->first();
    }

    public static function network(string $key): ?CafeteriaServiceNetwork
    {
        return CafeteriaServiceNetwork::query()->where('code', self::networks()[$key]['code'])->first();
    }

    public static function cafeteria(string $networkKey, string $location): ?CafeteriaProvider
    {
        return CafeteriaProvider::query()->where('code', self::cafeteriaCode($networkKey, $location))->first();
    }

    /** @return list<string> every demo organization id (root included) */
    public static function organizationIds(): array
    {
        return Organization::query()->where('metadata->demo_dataset', self::TAG)->pluck('id')->all();
    }
}
