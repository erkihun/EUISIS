<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use App\Models\GrievanceLetterTemplate;
use App\Models\GrievanceReasonCode;
use App\Models\GrievanceSlaProfile;
use Illuminate\Database\Seeder;

/**
 * Grievance Management defaults (docs/grievance-management.md §9). Idempotent:
 * every row is keyed so a re-run adds nothing and never overwrites an edit.
 *
 * These are STARTING values that the policy owners must confirm. Nothing here
 * is presented as official policy: the one SLA profile carries over the first
 * module's "3 working days", and reason codes are neutral wording to edit.
 */
class GrievanceDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $this->codeRules();
        $this->reasonCodes();
        $this->slaProfiles();
        $this->letterTemplates();
    }

    private function codeRules(): void
    {
        $rules = [
            [CodeRuleEntityType::GrievanceCase, 'Grievance Case Number', 'የቅሬታ ጉዳይ ቁጥር', 'GRV', 6],
            [CodeRuleEntityType::GrievanceDecision, 'Grievance Decision Number', 'የቅሬታ ውሳኔ ቁጥር', 'GRD', 6],
            [CodeRuleEntityType::GrievanceLetter, 'Grievance Letter Reference', 'የቅሬታ ደብዳቤ የወጪ ቁጥር', 'GRL', 6],
        ];

        foreach ($rules as [$type, $nameEn, $nameAm, $prefix, $length]) {
            if (CodeRule::query()->where('entity_type', $type->value)->whereNull('scope_type')->exists()) {
                continue;
            }
            CodeRule::query()->create([
                'entity_type' => $type->value,
                'scope_type' => null,
                'scope_id' => null,
                'active_scope_key' => CodeRule::buildActiveScopeKey($type, null, null),
                'name_en' => $nameEn,
                'name_am' => $nameAm,
                'prefix' => $prefix,
                'suffix' => null,
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'separator' => '-',
                'sequence_length' => $length,
                'next_number' => 1,
                'reset_frequency' => CodeRuleResetFrequency::Yearly,
                'year_format' => 'Y',
                'is_active' => true,
                'allow_manual_override' => false,
                'require_approval_for_override' => true,
            ]);
        }
    }

    private function reasonCodes(): void
    {
        $codes = [
            'intake_return' => [
                ['INCOMPLETE_INFORMATION', 'Information is incomplete', 'መረጃው ያልተሟላ ነው'],
                ['SUPPORTING_DOCUMENT_NEEDED', 'A supporting document is needed', 'ደጋፊ ሰነድ ያስፈልጋል'],
                ['UNCLEAR_REQUEST', 'The request is unclear', 'ጥያቄው ግልጽ አይደለም'],
            ],
            // Rejection at intake stays off in settings until policy defines
            // grounds; these are placeholders for the policy owner to confirm.
            'intake_rejection' => [
                ['OUTSIDE_SCOPE', 'Outside the grievance process scope', 'ከቅሬታ ሂደቱ ወሰን ውጭ'],
                ['DUPLICATE_CASE', 'Duplicate of an existing case', 'የነባር ጉዳይ ድግግሞሽ'],
            ],
            'withdrawal' => [
                ['RESOLVED_INFORMALLY', 'Resolved informally', 'በመግባባት ተፈቷል'],
                ['PERSONAL_DECISION', 'Personal decision of the complainant', 'የቅሬታ አቅራቢው የግል ውሳኔ'],
                ['OTHER', 'Other reason', 'ሌላ ምክንያት'],
            ],
            'closure' => [
                ['DECISION_FINAL', 'Final decision issued', 'የመጨረሻ ውሳኔ ተሰጥቷል'],
                ['APPEAL_PERIOD_EXPIRED', 'Appeal period expired', 'የይግባኝ ጊዜ አልፏል'],
                ['OUTCOME_ACCEPTED', 'Complainant accepted the outcome', 'ቅሬታ አቅራቢው ውጤቱን ተቀብሏል'],
                ['EXTERNAL_REFERRAL_COMPLETED', 'External referral completed', 'የውጭ ሪፈራል ተጠናቋል'],
                ['WITHDRAWN', 'Grievance withdrawn', 'ቅሬታው ተነስቷል'],
                ['OTHER', 'Other reason', 'ሌላ ምክንያት'],
            ],
            'reopen' => [
                ['NEW_EVIDENCE', 'New material evidence', 'አዲስ ወሳኝ ማስረጃ'],
                ['PROCEDURAL_ERROR', 'Procedural error found', 'የሥነ-ሥርዓት ስህተት ተገኝቷል'],
                ['ORDERED_BY_AUTHORITY', 'Ordered by a competent authority', 'ሥልጣን ባለው አካል ታዟል'],
            ],
            'reassignment' => [
                ['CONFLICT_OF_INTEREST', 'Conflict of interest', 'የጥቅም ግጭት'],
                ['WRONG_HANDLER', 'Routed to the wrong handler', 'ወደ ተሳሳተ አካል ተመርቷል'],
                ['HANDLER_UNAVAILABLE', 'Handler unavailable', 'አካሉ አይገኝም'],
            ],
        ];

        foreach ($codes as $type => $rows) {
            foreach ($rows as $i => [$code, $en, $am]) {
                GrievanceReasonCode::query()->firstOrCreate(
                    ['type' => $type, 'code' => $code],
                    ['name_en' => $en, 'name_am' => $am, 'is_active' => true, 'sort_order' => ($i + 1) * 10],
                );
            }
        }
    }

    private function slaProfiles(): void
    {
        $name = 'Default committee resolution (first-module baseline)';
        if (GrievanceSlaProfile::query()->where('name_en', $name)->exists()) {
            return;
        }

        GrievanceSlaProfile::query()->create([
            'name_en' => $name,
            'name_am' => 'ነባሪ የኮሚቴ መፍቻ ጊዜ (የመጀመሪያው ሞጁል መነሻ)',
            'purpose' => 'resolution',
            'handler_type' => 'committee',
            'resolution_days' => 3,
            'day_type' => 'working_days',
            'start_point' => 'on_assignment',
            'warning_thresholds' => ['percent' => [50], 'days_remaining' => [1], 'due_today' => true],
            'auto_escalate' => true,
            'priority' => 1000,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);
    }

    private function letterTemplates(): void
    {
        foreach ($this->templates() as [$type, $language, $name, $subject, $body]) {
            GrievanceLetterTemplate::query()->firstOrCreate(
                ['organization_id' => null, 'template_type' => $type, 'language' => $language, 'name' => $name],
                [
                    'subject_template' => $subject,
                    'body_template' => $body,
                    'signature_config' => ['show_signature' => true, 'show_name' => true, 'show_position' => true],
                    'seal_config' => ['allow_seal' => true],
                    'effective_from' => '2026-01-01',
                    'is_active' => true,
                ],
            );
        }
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    private function templates(): array
    {
        return [
            ['acknowledgment', 'en', 'Acknowledgment (English)', 'Acknowledgment of grievance {{case_number}}',
                "Dear {{recipient_name}},\n\nWe acknowledge receipt of your grievance registered as {{case_number}} on {{submission_date}}, concerning \"{{subject}}\".\n\nYour grievance has been referred to {{handler_name}} for review. You will be informed of the outcome in writing.\n\nPlease quote the case number in any further correspondence."],
            ['acknowledgment', 'am', 'የቅሬታ መቀበያ (አማርኛ)', 'የቅሬታ ቁጥር {{case_number}} መቀበያ',
                "ለ{{recipient_name}}\n\nበ{{submission_date}} በቁጥር {{case_number}} የተመዘገበውን \"{{subject}}\" የሚመለከት ቅሬታዎን መቀበላችንን እናሳውቃለን።\n\nቅሬታዎ ለግምገማ ወደ {{handler_name}} ተመርቷል። ውጤቱ በጽሑፍ ይገለጽልዎታል።\n\nለቀጣይ ግንኙነት የጉዳይ ቁጥሩን ይጥቀሱ።"],
            ['hearing_notice', 'en', 'Hearing notice (English)', 'Notice of hearing — grievance {{case_number}}',
                "Dear {{recipient_name}},\n\nYou are invited to a hearing on grievance {{case_number}}.\n\nDate: {{hearing_date}}\nTime: {{hearing_time}}\nMode: {{hearing_mode}}\nLocation: {{hearing_location}}\n\nPlease confirm your attendance."],
            ['hearing_notice', 'am', 'የችሎት ጥሪ (አማርኛ)', 'የችሎት ጥሪ — ቅሬታ {{case_number}}',
                "ለ{{recipient_name}}\n\nበቅሬታ ቁጥር {{case_number}} ላይ በሚካሄደው ችሎት እንዲገኙ ተጋብዘዋል።\n\nቀን፡ {{hearing_date}}\nሰዓት፡ {{hearing_time}}\nሁኔታ፡ {{hearing_mode}}\nቦታ፡ {{hearing_location}}\n\nመገኘትዎን ያረጋግጡ።"],
            ['information_request', 'en', 'Request for information (English)', 'Request for information — grievance {{case_number}}',
                "Dear {{recipient_name}},\n\nIn connection with grievance {{case_number}}, we request the following information:\n\n{{information_request}}\n\nPlease respond by {{information_due_date}}."],
            ['information_request', 'am', 'የመረጃ ጥያቄ (አማርኛ)', 'የመረጃ ጥያቄ — ቅሬታ {{case_number}}',
                "ለ{{recipient_name}}\n\nከቅሬታ ቁጥር {{case_number}} ጋር በተያያዘ የሚከተለውን መረጃ እንጠይቃለን፡\n\n{{information_request}}\n\nእስከ {{information_due_date}} ድረስ ምላሽ ይስጡ።"],
            ['decision_letter', 'en', 'Decision letter (English)', 'Decision on grievance {{case_number}}',
                "Dear {{recipient_name}},\n\nHaving reviewed your grievance {{case_number}} concerning \"{{subject}}\", {{handler_name}} has decided as follows on {{decision_date}}:\n\n{{decision_text}}\n\n{{appeal_notice}}"],
            ['decision_letter', 'am', 'የውሳኔ ደብዳቤ (አማርኛ)', 'በቅሬታ ቁጥር {{case_number}} ላይ የተሰጠ ውሳኔ',
                "ለ{{recipient_name}}\n\n\"{{subject}}\"ን የሚመለከተውን በቁጥር {{case_number}} የቀረበ ቅሬታዎን {{handler_name}} ከመረመረ በኋላ በ{{decision_date}} የሚከተለውን ወስኗል፡\n\n{{decision_text}}\n\n{{appeal_notice}}"],
            ['appeal_acknowledgment', 'en', 'Appeal acknowledgment (English)', 'Acknowledgment of appeal — grievance {{case_number}}',
                "Dear {{recipient_name}},\n\nWe acknowledge your appeal against the decision on grievance {{case_number}}. The appeal has been referred to {{handler_name}}."],
            ['appeal_acknowledgment', 'am', 'የይግባኝ መቀበያ (አማርኛ)', 'የይግባኝ መቀበያ — ቅሬታ {{case_number}}',
                "ለ{{recipient_name}}\n\nበቅሬታ ቁጥር {{case_number}} ውሳኔ ላይ ያቀረቡትን ይግባኝ ተቀብለናል። ይግባኙ ወደ {{handler_name}} ተመርቷል።"],
            ['escalation_notice', 'en', 'Escalation notice (English)', 'Grievance {{case_number}} escalated',
                "Dear {{recipient_name}},\n\nGrievance {{case_number}} has been escalated to {{handler_name}} in accordance with the grievance procedure."],
            ['escalation_notice', 'am', 'የማሳደጊያ ማሳወቂያ (አማርኛ)', 'ቅሬታ ቁጥር {{case_number}} ከፍ ብሏል',
                "ለ{{recipient_name}}\n\nቅሬታ ቁጥር {{case_number}} በቅሬታ አፈታት ሥነ-ሥርዓቱ መሠረት ወደ {{handler_name}} ከፍ ብሏል።"],
            ['closure_letter', 'en', 'Closure letter (English)', 'Closure of grievance {{case_number}}',
                "Dear {{recipient_name}},\n\nGrievance {{case_number}} has been closed. Reason: {{closure_reason}}."],
            ['closure_letter', 'am', 'የመዝጊያ ደብዳቤ (አማርኛ)', 'የቅሬታ ቁጥር {{case_number}} መዝጊያ',
                "ለ{{recipient_name}}\n\nቅሬታ ቁጥር {{case_number}} ተዘግቷል። ምክንያት፡ {{closure_reason}}።"],
        ];
    }
}
