<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The cafeteria module's permissions (the EUISIS names and labels, from the
 * EUISIS permission seed), as the platform's role editor lists them and the
 * three system roles receive them.
 */
final class CafeteriaPermissions
{
    /** @var array<string, array<string, array{0: string, 1: string}>> group => name => [English, Amharic] */
    public const CATALOG = [
        'cafeteria_providers' => [
            'cafeteria_providers.viewAny' => ['List Cafeteria Providers', 'የካፌቴሪያ አቅራቢዎችን ይዘርዝሩ'],
            'cafeteria_providers.view' => ['View Cafeteria Provider', 'የካፌቴሪያ አቅራቢ ይመልከቱ'],
            'cafeteria_providers.create' => ['Create Cafeteria Providers', 'የካፌቴሪያ አቅራቢዎችን ይፍጠሩ'],
            'cafeteria_providers.update' => ['Update Cafeteria Providers', 'የካፌቴሪያ አቅራቢዎችን ያዘምኑ'],
            'cafeteria_providers.archive' => ['Archive Cafeteria Providers', 'የካፌቴሪያ አቅራቢዎችን ወደ ማህደር ያስቀምጡ'],
            'cafeteria_providers.restore' => ['Restore Cafeteria Providers', 'የካፌቴሪያ አቅራቢዎችን ከማህደር ይመልሱ'],
            'cafeteria_providers.viewAll' => ['View All Cafeteria Providers', 'ሁሉንም የካፌቴሪያ አቅራቢዎች ይመልከቱ'],
            'cafeteria_providers.assignInstitution' => ['Assign Cafeteria Provider Institutions', 'ለካፌቴሪያ አቅራቢ ተቋማትን ይመድቡ'],
        ],
        'cafeteria_settings' => [
            'cafeteria_settings.view' => ['View Cafeteria Settings', 'የካፌቴሪያ ቅንብሮችን ይመልከቱ'],
            'cafeteria_settings.update' => ['Update Cafeteria Settings', 'የካፌቴሪያ ቅንብሮችን ያዘምኑ'],
            'cafeteria_day_rules.view' => ['View Cafeteria Day Rules', 'የካፌቴሪያ ቀን ደንቦችን ይመልከቱ'],
            'cafeteria_day_rules.update' => ['Update Cafeteria Day Rules', 'የካፌቴሪያ ቀን ደንቦችን ያዘምኑ'],
            'cafeteria_holidays.create' => ['Manage Cafeteria Holidays', 'የካፌቴሪያ ዕረፍት ቀናትን ያስተዳድሩ'],
            'cafeteria_subsidy_rules.view' => ['View Cafeteria Subsidy Rules', 'የካፌቴሪያ ድጎማ ደንቦችን ይመልከቱ'],
            'cafeteria_subsidy_rules.create' => ['Create Cafeteria Subsidy Rules', 'የካፌቴሪያ ድጎማ ደንቦችን ይፍጠሩ'],
            'cafeteria_subsidy_rules.update' => ['Update Cafeteria Subsidy Rules', 'የካፌቴሪያ ድጎማ ደንቦችን ያዘምኑ'],
            'cafeteria_subsidy_rules.archive' => ['Archive Cafeteria Subsidy Rules', 'የካፌቴሪያ ድጎማ ደንቦችን ወደ ማህደር ያስቀምጡ'],
            'cafeteria_special_days.view' => ['View Cafeteria Special Days', 'የካፌቴሪያ ልዩ ቀናትን ይመልከቱ'],
            'cafeteria_special_days.create' => ['Create Cafeteria Special Days', 'የካፌቴሪያ ልዩ ቀናትን ይፍጠሩ'],
            'cafeteria_special_days.update' => ['Update Cafeteria Special Days', 'የካፌቴሪያ ልዩ ቀናትን ያዘምኑ'],
            'cafeteria_special_days.archive' => ['Archive Cafeteria Special Days', 'የካፌቴሪያ ልዩ ቀናትን ወደ ማህደር ያስቀምጡ'],
            'cafeteria_special_days.restore' => ['Restore Cafeteria Special Days', 'የካፌቴሪያ ልዩ ቀናትን ከማህደር ይመልሱ'],
        ],
        'cafeteria_transactions' => [
            'cafeteria_transactions.view' => ['View Cafeteria Transactions', 'የካፌቴሪያ ግብይቶችን ይመልከቱ'],
            'cafeteria_transactions.scan' => ['Scan Cafeteria Cards', 'የካፌቴሪያ ካርዶችን ይቃኙ'],
            'cafeteria_transactions.reverse' => ['Reverse Cafeteria Transactions', 'የካፌቴሪያ ግብይቶችን ይቀልብሱ'],
            'cafeteria_transactions.export' => ['Export Cafeteria Transactions', 'የካፌቴሪያ ግብይቶችን ወደ ፋይል ያውጡ'],
        ],
        'cafeteria_reports' => [
            'cafeteria_reports.view' => ['View Cafeteria Reports', 'የካፌቴሪያ ሪፖርቶችን ይመልከቱ'],
            'cafeteria_reports.generate' => ['Generate Cafeteria Reports', 'የካፌቴሪያ ሪፖርቶችን ያመንጩ'],
            'cafeteria_reports.export' => ['Export Cafeteria Reports', 'የካፌቴሪያ ሪፖርቶችን ወደ ፋይል ያውጡ'],
        ],
        'cafeteria_ledger' => [
            'cafeteria_ledger.view' => ['View Cafeteria Ledger', 'የካፌቴሪያ የሂሳብ መዝገብ ይመልከቱ'],
        ],
        'cafeteria_exclusions' => [
            'cafeteria_employee_exclusions.view' => ['View Cafeteria Exclusions', 'የካፌቴሪያ ማግለያዎችን ይመልከቱ'],
            'cafeteria_employee_exclusions.create' => ['Create Cafeteria Exclusions', 'የካፌቴሪያ ማግለያዎችን ይፍጠሩ'],
            'cafeteria_employee_exclusions.update' => ['Update Cafeteria Exclusions', 'የካፌቴሪያ ማግለያዎችን ያዘምኑ'],
            'cafeteria_employee_exclusions.end' => ['End Cafeteria Exclusions', 'የካፌቴሪያ ማግለያዎችን ያቁሙ'],
            'cafeteria_employee_exclusions.archive' => ['Archive Cafeteria Exclusions', 'የካፌቴሪያ ማግለያዎችን ወደ ማህደር ያስቀምጡ'],
        ],
        'cafeteria_provider_users' => [
            'cafeteria-provider-users.viewAny' => ['List Provider Users', 'የአቅራቢ ተጠቃሚዎችን ይዘርዝሩ'],
            'cafeteria-provider-users.view' => ['View Provider User', 'የአቅራቢ ተጠቃሚ ይመልከቱ'],
            'cafeteria-provider-users.create' => ['Create Provider User', 'የአቅራቢ ተጠቃሚ ይፍጠሩ'],
            'cafeteria-provider-users.update' => ['Update Provider User', 'የአቅራቢ ተጠቃሚ ያዘምኑ'],
            'cafeteria-provider-users.resetPassword' => ['Reset Provider User Password', 'የአቅራቢ ተጠቃሚ የይለፍ ቃል ዳግም ያቀናብሩ'],
            'cafeteria-provider-users.suspend' => ['Suspend Provider User', 'የአቅራቢ ተጠቃሚ ያቁሙ'],
            'cafeteria-provider-users.activate' => ['Activate Provider User', 'የአቅራቢ ተጠቃሚ ገቢር ያድርጉ'],
            'cafeteria-provider-users.delete' => ['Delete Provider User', 'የአቅራቢ ተጠቃሚ ይሰርዙ'],
            'cafeteria-provider-users.restore' => ['Restore Provider User', 'የአቅራቢ ተጠቃሚን ከማህደር ይመልሱ'],
        ],
        'cafeteria_portal' => [
            'cafeteria-portal.login' => ['Log In to Provider Portal', 'ወደ አቅራቢ ፖርታል ይግቡ'],
            'cafeteria-portal.view' => ['Access Provider Portal', 'የአቅራቢ ፖርታል ይጠቀሙ'],
            'cafeteria-portal.viewDashboard' => ['View Portal Dashboard', 'የፖርታል ዳሽቦርድ ይመልከቱ'],
            'cafeteria-portal.scan' => ['Scan Cafeteria Cards', 'የካፌቴሪያ ካርዶችን ይቃኙ'],
            'cafeteria-portal.viewTransactions' => ['View Portal Transactions', 'የፖርታል ግብይቶችን ይመልከቱ'],
            'cafeteria-portal.viewLedger' => ['View Portal Ledger', 'የፖርታል ሂሳብ መዝገብ ይመልከቱ'],
            'cafeteria-portal.manageMenus' => ['Manage Portal Menus', 'የፖርታል ምናሌዎችን ያስተዳድሩ'],
            'cafeteria-portal.manageOrders' => ['Manage Portal Orders', 'የፖርታል ትዕዛዞችን ያስተዳድሩ'],
            'cafeteria-portal.viewReports' => ['View Portal Reports', 'የፖርታል ሪፖርቶችን ይመልከቱ'],
            'cafeteria-portal.updateProfile' => ['Update Portal Profile', 'የፖርታል መገለጫ ያዘምኑ'],
            'cafeteria-portal.impersonate' => ['Impersonate Provider Users', 'የአቅራቢ ተጠቃሚን ወክለው ይግቡ'],
            'cafeteria-portal.exportTransactions' => ['Export Portal Transactions', 'የፖርታል ግብይቶችን ወደ ፋይል ያውጡ'],
            'provider-cafeteria-transactions.export' => ['Export Provider Transactions', 'የአቅራቢ ግብይቶችን ወደ ፋይል ያውጡ'],
            'provider-cafeteria-payment-claims.export' => ['Export Provider Payment Claims', 'የአቅራቢ የክፍያ ጥያቄዎችን ወደ ፋይል ያውጡ'],
        ],
        'cafeteria_networks' => [
            'cafeteria_networks.view' => ['View Cafeteria Networks', 'የካፌቴሪያ ኔትወርኮችን ይመልከቱ'],
            'cafeteria_networks.manage' => ['Manage Cafeteria Networks', 'የካፌቴሪያ ኔትወርኮችን ያስተዳድሩ'],
        ],
        'cafeteria_access' => [
            'cafeteria_access.view' => ['View Organization Cafeteria Access', 'የተቋም የካፌቴሪያ ፈቃድን ይመልከቱ'],
            'cafeteria_access.manage' => ['Manage Organization Cafeteria Access', 'የተቋም የካፌቴሪያ ፈቃድን ያስተዳድሩ'],
            'cafeteria_access.approve' => ['Approve Organization Cafeteria Access', 'የተቋም የካፌቴሪያ ፈቃድን ያጽድቁ'],
        ],
        'cafeteria_assignments' => [
            'cafeteria_assignments.view' => ['View Cafeteria Service Assignments', 'የካፌቴሪያ አገልግሎት ምደባዎችን ይመልከቱ'],
            'cafeteria_assignments.create' => ['Create Cafeteria Service Assignments', 'የካፌቴሪያ አገልግሎት ምደባ ይፍጠሩ'],
            'cafeteria_assignments.update' => ['Update Cafeteria Service Assignments', 'የካፌቴሪያ አገልግሎት ምደባን ያዘምኑ'],
            'cafeteria_assignments.end' => ['End Cafeteria Service Assignments', 'የካፌቴሪያ አገልግሎት ምደባን ያቋርጡ'],
            'cafeteria_assignments.approve' => ['Approve Cafeteria Service Assignments', 'የካፌቴሪያ አገልግሎት ምደባን ያጽድቁ'],
        ],
        'cafeteria_policies' => [
            'cafeteria_policies.view' => ['View Cafeteria Service Policies', 'የካፌቴሪያ አገልግሎት ፖሊሲዎችን ይመልከቱ'],
            'cafeteria_policies.create' => ['Create Cafeteria Service Policies', 'የካፌቴሪያ አገልግሎት ፖሊሲ ይፍጠሩ'],
            'cafeteria_policies.update_draft' => ['Edit Draft Cafeteria Policies', 'ረቂቅ የካፌቴሪያ ፖሊሲዎችን ያርትዑ'],
            'cafeteria_policies.submit' => ['Submit Cafeteria Policies for Review', 'የካፌቴሪያ ፖሊሲዎችን ለግምገማ ያቅርቡ'],
            'cafeteria_policies.review' => ['Review Cafeteria Policies', 'የካፌቴሪያ ፖሊሲዎችን ይገምግሙ'],
            'cafeteria_policies.approve' => ['Approve Cafeteria Policies', 'የካፌቴሪያ ፖሊሲዎችን ያጽድቁ'],
            'cafeteria_policies.activate' => ['Activate Cafeteria Policies', 'የካፌቴሪያ ፖሊሲዎችን ገቢር ያድርጉ'],
            'cafeteria_policies.end' => ['End or Cancel Cafeteria Policies', 'የካፌቴሪያ ፖሊሲዎችን ያቋርጡ ወይም ይሰርዙ'],
        ],
        'cafeteria_settlements' => [
            'cafeteria_settlements.view' => ['View Cafeteria Settlements', 'የካፌቴሪያ ሂሳብ ማወራረጃዎችን ይመልከቱ'],
            'cafeteria_settlements.manage' => ['Manage Cafeteria Settlements', 'የካፌቴሪያ ሂሳብ ማወራረጃዎችን ያስተዳድሩ'],
        ],
        'public_holidays' => [
            'public_holidays.viewAny' => ['List Public Holidays', 'የሕዝብ በዓላትን ዝርዝር ይመልከቱ'],
            'public_holidays.view' => ['View Public Holidays', 'የሕዝብ በዓላትን ይመልከቱ'],
            'public_holidays.create' => ['Create Public Holidays', 'የሕዝብ በዓላትን ይፍጠሩ'],
            'public_holidays.update' => ['Update Public Holidays', 'የሕዝብ በዓላትን ያዘምኑ'],
            'public_holidays.archive' => ['Archive Public Holidays', 'የሕዝብ በዓላትን ወደ ማህደር ያስቀምጡ'],
        ],
    ];

    /** @return list<string> every cafeteria permission */
    public static function all(): array
    {
        return array_keys(array_merge(...array_values(self::CATALOG)));
    }

    /** @return array<string, list<string>> what each system role receives */
    public static function grants(): array
    {
        $all = self::all();
        $reads = array_values(array_filter($all, fn (string $name): bool => preg_match('/\.(view|viewAny|viewAll)$/', $name) === 1));
        $operates = array_values(array_filter($all, fn (string $name): bool => preg_match('/\.(scan|create|update|update_draft|submit|export|generate|restore|reverse|archive|end|assignInstitution|resetPassword|suspend|activate)$/', $name) === 1));

        return [
            'core_viewer' => $reads,
            'core_officer' => array_values(array_unique([...$reads, ...$operates])),
            'core_admin' => $all,
        ];
    }
}
