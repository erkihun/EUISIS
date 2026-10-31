<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reference rows court-ms's own migrations wrote (ADR 0020): the staff
 * performance categories and criteria. Permissions and roles come from the
 * court seeders (`court:staff` runs them), as they did in court-ms.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (DB::table('court_performance_evaluation_categories')->doesntExist()) {
            DB::table('court_performance_evaluation_categories')->insert([
                ['slug' => 'efficiency', 'name' => 'Efficiency', 'name_am' => 'ቅልጥፍና', 'active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['slug' => 'quality', 'name' => 'Quality', 'name_am' => 'ጥራት', 'active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['slug' => 'conduct', 'name' => 'Conduct', 'name_am' => 'ሥነ ምግባር', 'active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
                ['slug' => 'general', 'name' => 'General', 'name_am' => 'አጠቃላይ', 'active' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (DB::table('court_performance_evaluation_criteria')->doesntExist()) {
            DB::table('court_performance_evaluation_criteria')->insert([
                ['name' => 'Case Management', 'name_am' => 'የጉዳይ አስተዳደር', 'category' => 'efficiency', 'source' => null, 'weight' => 20, 'description' => 'Timely handling and resolution of assigned cases.', 'active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Quality of Work', 'name_am' => 'የሥራ ጥራት', 'category' => 'quality', 'source' => null, 'weight' => 20, 'description' => 'Accuracy, thoroughness and quality of decisions made.', 'active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Attendance & Punctuality', 'name_am' => 'መገኘት እና ሰዓት አከባበር', 'category' => 'conduct', 'source' => null, 'weight' => 15, 'description' => 'Regularity of attendance and adherence to schedule.', 'active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Professionalism', 'name_am' => 'ሙያዊ ሥነ-ምግባር', 'category' => 'conduct', 'source' => null, 'weight' => 15, 'description' => 'Professional conduct and ethical behavior.', 'active' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Teamwork & Collaboration', 'name_am' => 'ቡድን ሥራ', 'category' => 'conduct', 'source' => null, 'weight' => 15, 'description' => 'Ability to work effectively within the team.', 'active' => true, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Technical Knowledge', 'name_am' => 'ቴክኒካዊ እውቀት', 'category' => 'quality', 'source' => null, 'weight' => 15, 'description' => 'Knowledge of laws, procedures and court regulations.', 'active' => true, 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Decisions upheld on appeal', 'name_am' => 'ይግባኝ ላይ የጸኑ ውሳኔዎች', 'category' => 'quality', 'source' => 'appeal_outcomes', 'weight' => 15, 'description' => 'Worked out from the appellate court\'s outcomes in the period: a decision confirmed scores 10, modified 5, reversed 0. Left out when no appeal was decided.', 'active' => true, 'sort_order' => 7, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        // The rows go with the court tables (create_court_module_tables).
    }
};
