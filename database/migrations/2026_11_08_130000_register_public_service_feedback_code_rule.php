<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Service feedback gets its own reference from a Code Rule (owner request
 * 2026-11-08), like complaints (PSC) and Tip-offs (PTO). Editable afterwards
 * on the Code Rules page. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::pretending()) {
            return;
        }

        CodeRule::query()->firstOrCreate(
            ['active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::PublicServiceFeedback)],
            [
                'entity_type' => CodeRuleEntityType::PublicServiceFeedback,
                'name_en' => 'Public Service Feedback Reference',
                'name_am' => 'የተገልጋይ አስተያየት መለያ ቁጥር',
                'prefix' => 'PSF',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'separator' => '-',
                'sequence_length' => 6,
                'next_number' => 1,
                'reset_frequency' => CodeRuleResetFrequency::Yearly,
                'year_format' => 'Y',
                'is_active' => true,
                'allow_manual_override' => false,
                'require_approval_for_override' => true,
            ],
        );
    }

    public function down(): void
    {
        // Keep the rule and the references it issued.
    }
};
