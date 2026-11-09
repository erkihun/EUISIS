<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use Database\Seeders\CodeRuleSeeder;
use Illuminate\Database\Migrations\Migration;

/*
 * Record numbers built in code until now are numbered by code rules (2026-10-31): each gets
 * its default rule, in the earlier shape, so existing databases keep creating these records.
 * An administrator's own active rule for an entity type is left as it is.
 */
return new class extends Migration
{
    private const ENTITY_TYPES = [
        CodeRuleEntityType::ReceptionVisit,
        CodeRuleEntityType::ReceptionAppointment,
        CodeRuleEntityType::CardPrintBatch,
        CodeRuleEntityType::PositionEstablishment,
        CodeRuleEntityType::SupportTicket,
        CodeRuleEntityType::VacancyAnnouncement,
        CodeRuleEntityType::VacancyApplication,
        CodeRuleEntityType::TransportTrip,
        CodeRuleEntityType::TransportSettlement,
        CodeRuleEntityType::TransportInvoice,
        CodeRuleEntityType::CafeteriaSettlement,
        CodeRuleEntityType::CafeteriaReport,
        CodeRuleEntityType::FieldWorkRequest,
        CodeRuleEntityType::OrganizationalChangeRequest,
        CodeRuleEntityType::PerformanceAppeal,
        CodeRuleEntityType::ManpowerPlan,
        CodeRuleEntityType::LegacyBaseline,
        CodeRuleEntityType::DesignImportBatch,
        CodeRuleEntityType::OrganizationDesignStudy,
    ];

    public function up(): void
    {
        foreach (self::ENTITY_TYPES as $entityType) {
            CodeRuleSeeder::ensureFor($entityType);
        }
    }

    public function down(): void
    {
        // The rules may have been edited and have numbered records since; they stay.
    }
};
