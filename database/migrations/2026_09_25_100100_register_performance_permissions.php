<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\DailyActivity\DailyActivityRoles;
use App\Support\Performance\PerformanceRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers EPMS permissions and roles, and seeds EDITABLE default rating
 * scales and a competency framework. These are starting data, not policy in
 * code: administrators change them in Performance Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = require database_path('seeders/data/performance-permissions.php');
        foreach ($catalog as $entry) {
            Permission::query()->updateOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], array_diff_key($entry, ['name' => true]));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = array_column($catalog, 'name');
        foreach ([
            'Super Admin' => $all,
            'System Admin' => $all,
            'City Admin' => $all,
            'Public Service Bureau Admin' => $all,
            'Organizational Admin' => PerformanceRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
            'HR Officer' => PerformanceRoles::HR_PERMISSIONS,
        ] as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        foreach ([
            DailyActivityRoles::EMPLOYEE_ROLE => PerformanceRoles::EMPLOYEE_PERMISSIONS,
            PerformanceRoles::MANAGER_ROLE => PerformanceRoles::MANAGER_PERMISSIONS,
            PerformanceRoles::APPEAL_COMMITTEE_ROLE => PerformanceRoles::APPEAL_COMMITTEE_PERMISSIONS,
        ] as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->forceFill(['scope_type' => 'scoped'])->save();
            $role->givePermissionTo($permissions);
        }

        $this->seedDefaults();
    }

    private function seedDefaults(): void
    {
        if (DB::table('performance_rating_scales')->exists()) {
            return;
        }

        $now = now();
        $resultScale = (string) Str::uuid7();
        $competencyScale = (string) Str::uuid7();
        DB::table('performance_rating_scales')->insert([
            ['id' => $resultScale, 'code' => 'RESULT-DEFAULT', 'name_en' => 'Default result bands', 'name_am' => 'ነባሪ የውጤት ደረጃዎች', 'scale_type' => 'RESULT', 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $competencyScale, 'code' => 'COMPETENCY-5', 'name_en' => 'Competency 1–5', 'name_am' => 'ብቃት 1–5', 'scale_type' => 'COMPETENCY', 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $bands = [
            [$resultScale, '90', null, null, 'Exceptional', 'እጅግ የላቀ'],
            [$resultScale, '80', '89.9999', null, 'Very Good', 'በጣም ጥሩ'],
            [$resultScale, '70', '79.9999', null, 'Good', 'ጥሩ'],
            [$resultScale, '60', '69.9999', null, 'Needs Improvement', 'መሻሻል ያስፈልገዋል'],
            [$resultScale, null, '59.9999', null, 'Unsatisfactory', 'አጥጋቢ ያልሆነ'],
            [$competencyScale, null, null, 1, 'Unsatisfactory', 'አጥጋቢ ያልሆነ'],
            [$competencyScale, null, null, 2, 'Needs Improvement', 'መሻሻል ያስፈልገዋል'],
            [$competencyScale, null, null, 3, 'Meets Expectations', 'የሚጠበቀውን ያሟላል'],
            [$competencyScale, null, null, 4, 'Exceeds Expectations', 'ከሚጠበቀው በላይ'],
            [$competencyScale, null, null, 5, 'Exceptional', 'እጅግ የላቀ'],
        ];
        foreach ($bands as $index => [$scale, $min, $max, $level, $en, $am]) {
            DB::table('performance_rating_bands')->insert([
                'id' => (string) Str::uuid7(), 'scale_id' => $scale, 'min_score' => $min, 'max_score' => $max, 'level_value' => $level,
                'label_en' => $en, 'label_am' => $am, 'sort_order' => $index, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $framework = (string) Str::uuid7();
        DB::table('competency_frameworks')->insert(['id' => $framework, 'code' => 'CORE', 'name_en' => 'Core public service competencies', 'name_am' => 'ዋና የሕዝብ አገልግሎት ብቃቶች', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([
            ['LEAD', 'Leadership', 'አመራር'], ['TEAM', 'Teamwork', 'የቡድን ሥራ'], ['INTEG', 'Integrity', 'ታማኝነት'],
            ['COMM', 'Communication', 'ተግባቦት'], ['SERV', 'Service Orientation', 'የአገልግሎት ተኮርነት'], ['PROB', 'Problem Solving', 'ችግር መፍታት'],
            ['INNOV', 'Innovation', 'ፈጠራ'], ['ACCT', 'Accountability', 'ተጠያቂነት'],
        ] as $index => [$code, $en, $am]) {
            DB::table('competencies')->insert([
                'id' => (string) Str::uuid7(), 'framework_id' => $framework, 'code' => $code, 'name_en' => $en, 'name_am' => $am,
                'is_active' => true, 'sort_order' => $index, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $names = array_column(require database_path('seeders/data/performance-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
