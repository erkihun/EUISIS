<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use Illuminate\Database\Seeder;

/**
 * Default code rules (reference data). Idempotent: an existing rule is never
 * changed, so an administrator's edits survive a re-run.
 */
class CodeRuleSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'entity_type' => CodeRuleEntityType::Organization->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Code',
                'name_am' => 'የድርጅት ኮድ',
                'prefix' => 'ORG',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 5,
            ],
            [
                'entity_type' => CodeRuleEntityType::OrganizationType->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Type Code',
                'name_am' => 'የድርጅት አይነት ኮድ',
                'prefix' => 'OT',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::Employee->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Employee Number',
                'name_am' => 'የሰራተኛ ቁጥር',
                'prefix' => 'EMP',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 6,
            ],
            [
                'entity_type' => CodeRuleEntityType::Position->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Job Position Code',
                'name_am' => 'የስራ መደብ ኮድ',
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
                'name_am' => 'የአገልግሎት አይነት ኮድ',
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
                'name_am' => 'የመብት ደንብ ኮድ',
                'prefix' => 'ETR',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::OrganizationUnit->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Unit Code',
                'name_am' => 'የድርጅት ዩኒት ኮድ',
                'prefix' => 'UNIT',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::OrganizationUnitType->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Unit Type Code',
                'name_am' => 'የድርጅት ዩኒት አይነት ኮድ',
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
                'name_am' => 'የሙያ ኮድ',
                'prefix' => 'OCC',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
        ];

        foreach ($defaults as $default) {
            CodeRule::query()->firstOrCreate(
                [
                    'entity_type' => $default['entity_type'],
                    'scope_type' => $default['scope_type'],
                    'scope_id' => $default['scope_id'],
                    'name_en' => $default['name_en'],
                ],
                [
                    ...$default,
                    'active_scope_key' => CodeRule::buildActiveScopeKey(
                        $default['entity_type'],
                        $default['scope_type'],
                        $default['scope_id'],
                    ),
                    'suffix' => null,
                    'separator' => $default['separator'] ?? '-',
                    'next_number' => 1,
                    'reset_frequency' => CodeRuleResetFrequency::Never,
                    'year_format' => 'Y',
                    'is_active' => true,
                    'allow_manual_override' => false,
                    'require_approval_for_override' => true,
                ],
            );
        }
    }
}
