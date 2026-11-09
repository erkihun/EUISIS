<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::pretending()) {
            return;
        }

        $catalog = require database_path('seeders/data/permissions.php');
        foreach ($catalog as $entry) {
            if (in_array($entry['name'], ['service_feedback.complaints.view', 'service_feedback.complaints.review'], true)) {
                Permission::firstOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], $entry);
            }
        }

        // Intentionally no role assignment. An administrator grants these only
        // to the selected service-quality roles within their organization scope.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        CodeRule::query()->firstOrCreate(
            ['active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::PublicServiceComplaint)],
            [
                'entity_type' => CodeRuleEntityType::PublicServiceComplaint,
                'name_en' => 'Public Service Complaint Reference',
                'name_am' => 'የሕዝብ አገልግሎት ቅሬታ ማጣቀሻ',
                'prefix' => 'PSC',
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
        // Preserve assigned permissions and generated reference history. The
        // schema rollback guards against removing complaint records.
    }
};
