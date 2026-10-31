<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use Illuminate\Database\Seeder;

/**
 * Default code rules (reference data). Idempotent: an existing rule is never
 * changed, so an administrator's edits survive a re-run, and no default is
 * added where an administrator already runs an active rule for the entity.
 */
class CodeRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::defaults() as $default) {
            self::ensure($default);
        }
    }

    /** Ensures the default rule of one entity; migrations use it to add a new default to existing databases. */
    public static function ensureFor(CodeRuleEntityType $entityType): void
    {
        foreach (self::defaults() as $default) {
            if ($default['entity_type'] === $entityType->value) {
                self::ensure($default);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        return [
            [
                'entity_type' => CodeRuleEntityType::Organization->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Code',
                'name_am' => 'የተቋም ኮድ',
                'prefix' => 'ORG',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 5,
            ],
            [
                'entity_type' => CodeRuleEntityType::OrganizationType->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Type Code',
                'name_am' => 'የተቋም ዓይነት ኮድ',
                'prefix' => 'OT',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::Employee->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Employee Number',
                'name_am' => 'የሠራተኛ ቁጥር',
                'prefix' => 'EMP',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 6,
            ],
            [
                'entity_type' => CodeRuleEntityType::Position->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Job Position Code',
                'name_am' => 'የሥራ መደብ ኮድ',
                'prefix' => 'POS',
                // ownerCode/sequence, or ownerCode/hostCode/sequence when the
                // unit operates inside a host organization. The empty host
                // segment collapses because the separator is '/'.
                'format' => '{OWNER_ORG_CODE}/{HOST_ORG_CODE}/{SEQUENCE}',
                'separator' => '/',
                'sequence_length' => 2,
            ],
            [
                'entity_type' => CodeRuleEntityType::IdCard->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'ID Card Number',
                'name_am' => 'የመታወቂያ ካርድ ቁጥር',
                'prefix' => 'IDC',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 6,
            ],
            [
                'entity_type' => CodeRuleEntityType::ServiceType->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Service Type Code',
                'name_am' => 'የአገልግሎት ዓይነት ኮድ',
                'prefix' => 'SVT',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::ServiceProvider->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Service Provider Code',
                'name_am' => 'የአገልግሎት አቅራቢ ኮድ',
                'prefix' => 'SPR',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::EntitlementRule->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Entitlement Rule Code',
                'name_am' => 'የጥቅማ ጥቅም ደንብ ኮድ',
                'prefix' => 'ETR',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::OrganizationUnit->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Unit Code',
                'name_am' => 'የሥራ ክፍል ኮድ',
                'prefix' => 'UNIT',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::OrganizationUnitType->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Unit Type Code',
                'name_am' => 'የሥራ ክፍል ዓይነት ኮድ',
                'prefix' => 'UTYPE',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 3,
            ],
            [
                'entity_type' => CodeRuleEntityType::CardRequest->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Card Request Number',
                'name_am' => 'የካርድ ጥያቄ ቁጥር',
                'prefix' => 'CRQ',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 5,
            ],
            [
                'entity_type' => CodeRuleEntityType::Occupation->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Occupation Code',
                'name_am' => 'የሙያ ምድብ ኮድ',
                'prefix' => 'OCC',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                // Transfer applications are numbered on submission; without an
                // active rule no employee could apply.
                'entity_type' => CodeRuleEntityType::TransferApplication->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Transfer Application Number',
                'name_am' => 'የዝውውር ማመልከቻ ቁጥር',
                'prefix' => 'TRA',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 6,
            ],
            [
                'entity_type' => CodeRuleEntityType::ReceptionDesk->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Reception Desk Code',
                'name_am' => 'የመቀበያ ጠረጴዛ ኮድ',
                'prefix' => 'REC',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            // Record numbers that were built in code until 2026-10-31. Each default keeps the
            // number's earlier shape (prefix, date part, sequence length and restart), with a
            // sequence where a random suffix used to be. These records are numbered as they
            // are created, so without an active rule they could not be created at all.
            ...self::recordNumber(CodeRuleEntityType::ReceptionVisit, 'Reception Visit Reference', 'የእንግዳ ጉብኝት መለያ ቁጥር', 'VIS', '{PREFIX}-{DATE}-{SEQUENCE}', 4, CodeRuleResetFrequency::Daily),
            ...self::recordNumber(CodeRuleEntityType::ReceptionAppointment, 'Reception Appointment Reference', 'የቀጠሮ መለያ ቁጥር', 'APT', '{PREFIX}-{DATE}-{SEQUENCE}', 4, CodeRuleResetFrequency::Daily),
            ...self::recordNumber(CodeRuleEntityType::CardPrintBatch, 'ID Card Print Batch Number', 'የመታወቂያ ካርድ የሕትመት ጥቅል ቁጥር', 'BATCH', '{PREFIX}-{DATE}-{SEQUENCE}', 4, CodeRuleResetFrequency::Daily),
            ...self::recordNumber(CodeRuleEntityType::PositionEstablishment, 'Position Establishment Number', 'የሥራ መደብ ማቋቋሚያ ቁጥር', 'EST', '{PREFIX}-{YEAR}{MONTH}-{SEQUENCE}', 5, CodeRuleResetFrequency::Monthly),
            ...self::recordNumber(CodeRuleEntityType::SupportTicket, 'Support Ticket Number', 'የድጋፍ ጥያቄ ቁጥር', 'TKT', '{PREFIX}-{YEAR_SHORT}{MONTH}-{SEQUENCE}', 4, CodeRuleResetFrequency::Monthly),
            ...self::recordNumber(CodeRuleEntityType::VacancyAnnouncement, 'Vacancy Announcement Number', 'የክፍት የሥራ መደብ ማስታወቂያ ቁጥር', 'VCY', '{PREFIX}-{YEAR}{MONTH}-{SEQUENCE}', 4, CodeRuleResetFrequency::Monthly),
            ...self::recordNumber(CodeRuleEntityType::VacancyApplication, 'Vacancy Application Number', 'የክፍት የሥራ መደብ ማመልከቻ ቁጥር', 'APP', '{PREFIX}-{YEAR}{MONTH}-{SEQUENCE}', 5, CodeRuleResetFrequency::Monthly),
            ...self::recordNumber(CodeRuleEntityType::TransportTrip, 'Transport Trip Number', 'የትራንስፖርት ጉዞ ቁጥር', 'TRP', '{PREFIX}-{DATE}-{SEQUENCE}', 5, CodeRuleResetFrequency::Daily),
            ...self::recordNumber(CodeRuleEntityType::TransportSettlement, 'Transport Settlement Number', 'የትራንስፖርት ሂሳብ ማወራረጃ ቁጥር', 'TST', '{PREFIX}-{DATE}-{SEQUENCE}', 4, CodeRuleResetFrequency::Daily),
            ...self::recordNumber(CodeRuleEntityType::TransportInvoice, 'Transport Invoice Number', 'የትራንስፖርት የክፍያ መጠየቂያ ቁጥር', 'INV', '{PREFIX}-{YEAR}{MONTH}-{SEQUENCE}', 5, CodeRuleResetFrequency::Monthly),
            ...self::recordNumber(CodeRuleEntityType::CafeteriaSettlement, 'Cafeteria Settlement Number', 'የካፌቴሪያ ሂሳብ ማወራረጃ ቁጥር', 'SET', '{PREFIX}-{DATE}-{SEQUENCE}', 4, CodeRuleResetFrequency::Daily),
            ...self::recordNumber(CodeRuleEntityType::CafeteriaReport, 'Cafeteria Report Number', 'የካፌቴሪያ ሪፖርት ቁጥር', 'RPT', '{PREFIX}-{YEAR}-{SEQUENCE}', 4, CodeRuleResetFrequency::Yearly),
            ...self::recordNumber(CodeRuleEntityType::FieldWorkRequest, 'Field Work Request Reference', 'የመስክ ሥራ ጥያቄ መለያ ቁጥር', 'FW', '{PREFIX}-{DATE}-{SEQUENCE}', 4, CodeRuleResetFrequency::Daily),
            ...self::recordNumber(CodeRuleEntityType::OrganizationalChangeRequest, 'Organizational Change Request Number', 'የአደረጃጀት ለውጥ ጥያቄ ቁጥር', 'OCR', '{PREFIX}-{YEAR}-{SEQUENCE}', 6, CodeRuleResetFrequency::Yearly),
            ...self::recordNumber(CodeRuleEntityType::PerformanceAppeal, 'Performance Appeal Number', 'የአፈጻጸም ይግባኝ ቁጥር', 'PA', '{PREFIX}-{YEAR}-{SEQUENCE}', 5, CodeRuleResetFrequency::Yearly),
            ...self::recordNumber(CodeRuleEntityType::ManpowerPlan, 'Manpower Plan Reference', 'የሰው ኃይል ዕቅድ መለያ ቁጥር', 'MPP', '{PREFIX}-{YEAR}-{SEQUENCE}', 4, CodeRuleResetFrequency::Yearly),
            ...self::recordNumber(CodeRuleEntityType::LegacyBaseline, 'Legacy Organization Baseline Reference', 'የነባር አደረጃጀት መነሻ መለያ ቁጥር', 'LOB', '{PREFIX}-{YEAR}-{SEQUENCE}', 4, CodeRuleResetFrequency::Yearly),
            ...self::recordNumber(CodeRuleEntityType::DesignImportBatch, 'Design Import Batch Reference', 'የአደረጃጀት ጥናት ማስገቢያ ጥቅል መለያ ቁጥር', 'DIB', '{PREFIX}-{YEAR}-{SEQUENCE}', 4, CodeRuleResetFrequency::Yearly),
            ...self::recordNumber(CodeRuleEntityType::OrganizationDesignStudy, 'Organization Design Study Reference', 'የአደረጃጀት ጥናት መለያ ቁጥር', 'ODS', '{PREFIX}-{YEAR}-{SEQUENCE}', 4, CodeRuleResetFrequency::Yearly),
        ];
    }

    /**
     * The default rule of a record number, as a one-item list to spread into defaults().
     *
     * @return list<array<string, mixed>>
     */
    private static function recordNumber(CodeRuleEntityType $entityType, string $nameEn, string $nameAm, string $prefix, string $format, int $length, CodeRuleResetFrequency $reset): array
    {
        return [[
            'entity_type' => $entityType->value,
            'scope_type' => null,
            'scope_id' => null,
            'name_en' => $nameEn,
            'name_am' => $nameAm,
            'prefix' => $prefix,
            'format' => $format,
            'sequence_length' => $length,
            'reset_frequency' => $reset,
        ]];
    }

    /** @param  array<string, mixed>  $default */
    private static function ensure(array $default): void
    {
        $activeScopeKey = CodeRule::buildActiveScopeKey($default['entity_type'], $default['scope_type'], $default['scope_id']);

        // An administrator's own active rule for this entity and scope wins.
        if (CodeRule::query()->where('active_scope_key', $activeScopeKey)->exists()) {
            return;
        }

        CodeRule::query()->firstOrCreate(
            [
                'entity_type' => $default['entity_type'],
                'scope_type' => $default['scope_type'],
                'scope_id' => $default['scope_id'],
                'name_en' => $default['name_en'],
            ],
            [
                ...$default,
                'active_scope_key' => $activeScopeKey,
                'suffix' => null,
                'separator' => $default['separator'] ?? '-',
                'next_number' => 1,
                'reset_frequency' => $default['reset_frequency'] ?? CodeRuleResetFrequency::Never,
                'year_format' => 'Y',
                'is_active' => true,
                'allow_manual_override' => false,
                'require_approval_for_override' => true,
            ],
        );
    }
}
