<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The leave types every institution starts with, and one rule set holding
 * the figures of the Federal Civil Servants Proclamation (ADR 0039).
 *
 * The rule set is marked "not confirmed": the figures were read from
 * Proclamation No. 1064/2010 E.C. and must be checked by the institution
 * against the law that binds it before go-live. Figures the law text was not
 * read for (leave without pay, special leave) are left empty, not guessed.
 * Runs once; rows that already exist are left as the institution set them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $types = [
            // code, name_en, name_am, category, day_unit, pay_class, uses_balance, half_day, supervisor, document, employee_can_request, gender
            ['annual', 'Annual leave', 'የዓመት ዕረፍት ፈቃድ', 'annual', 'working', 'full', true, true, true, false, true, null],
            ['miscellaneous', 'Miscellaneous leave', 'ልዩ ልዩ ፈቃድ', 'personal', 'working', 'full', true, true, true, false, true, null],
            ['sick', 'Sick leave', 'የሕመም ፈቃድ', 'sick', 'calendar', 'by_period', false, false, false, false, true, null],
            ['maternity', 'Maternity leave', 'የወሊድ ፈቃድ', 'maternity', 'calendar', 'full', false, false, false, true, true, 'female'],
            ['paternity', 'Paternity leave', 'የአባትነት ፈቃድ', 'paternity', 'working', 'full', false, false, false, false, true, 'male'],
            ['unpaid', 'Leave without pay', 'ደመወዝ የማይከፈልበት ፈቃድ', 'unpaid', 'calendar', 'none', false, false, true, false, true, null],
            ['special', 'Special leave with pay', 'ደመወዝ የሚከፈልበት ልዩ ፈቃድ', 'special', 'working', 'full', false, false, false, true, true, null],
        ];

        $ids = [];
        foreach ($types as $index => [$code, $nameEn, $nameAm, $category, $unit, $pay, $balance, $half, $supervisor, $document, $employee, $gender]) {
            $existing = DB::table('leave_types')->where('code', $code)->value('id');
            if ($existing !== null) {
                $ids[$code] = (string) $existing;

                continue;
            }
            $ids[$code] = (string) Str::uuid7();
            DB::table('leave_types')->insert([
                'id' => $ids[$code], 'code' => $code, 'name_en' => $nameEn, 'name_am' => $nameAm, 'category' => $category,
                'day_unit' => $unit, 'pay_class' => $pay, 'uses_balance' => $balance, 'allows_half_day' => $half,
                'requires_supervisor_approval' => $supervisor, 'requires_document' => $document, 'employee_can_request' => $employee,
                'gender' => $gender, 'is_active' => true, 'is_system' => true, 'sort_order' => ($index + 1) * 10,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if (DB::table('leave_rule_sets')->exists()) {
            return;
        }

        $setId = (string) Str::uuid7();
        DB::table('leave_rule_sets')->insert([
            'id' => $setId,
            // The interface is read in Amharic first; an administrator may rename it.
            'name' => 'የመንግሥት ሠራተኞች የፈቃድ ሕጎች (ቁጥሮቹ ገና አልተመሳከሩም)',
            'legal_reference' => 'የፌዴራል መንግሥት ሠራተኞች አዋጅ ቁጥር 1064/2010፣ አንቀጽ 37-45። ከአዲስ አበባ ከተማ አስተዳደር አዋጅ ቁጥር 56/2010 እና ከፌዴራል አዋጅ ቁጥር 1353 ጋር መመሳከር አለበት።',
            // Hamle 1, 2010 E.C.: early enough for leave of earlier years to be registered.
            'effective_from' => '2018-07-08',
            'status' => 'active',
            'is_confirmed' => false,
            'notes' => 'የዓመቱ ሰባት ቀን ልዩ ልዩ ፈቃድ ወደ ቀጣዩ ዓመት አይተላለፍም። ደመወዝ ለማይከፈልበት ፈቃድ እና ለልዩ ፈቃድ ገደብ አልተቀመጠም፤ ተቋሙን የሚገዛውን ሕግ ቁጥሮች ያስገቡ።',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $rules = [
            'annual' => ['base_days' => 20, 'increment_per_service_year' => 1, 'max_days' => 30, 'waiting_months' => 11, 'carry_over_mode' => 'authorized', 'carry_over_years' => 2],
            'miscellaneous' => ['base_days' => 7, 'carry_over_mode' => 'none'],
            'sick' => ['settings' => [
                'window_months' => 12, 'max_months_in_window' => 8, 'long_window_years' => 4, 'max_months_in_long_window' => 12,
                'full_pay_months' => 6, 'probation_months' => 6, 'probation_max_months' => 1, 'certificate_from_days' => 3,
            ]],
            'maternity' => ['settings' => ['prenatal_days' => 30, 'postnatal_days' => 90, 'miscarriage_days' => 60]],
            'paternity' => ['max_days_per_request' => 10],
            'unpaid' => [],
            'special' => [],
        ];
        foreach ($rules as $code => $figures) {
            DB::table('leave_rules')->insert([
                'id' => (string) Str::uuid7(), 'leave_rule_set_id' => $setId, 'leave_type_id' => $ids[$code],
                'base_days' => $figures['base_days'] ?? null,
                'increment_per_service_year' => $figures['increment_per_service_year'] ?? null,
                'max_days' => $figures['max_days'] ?? null,
                'waiting_months' => $figures['waiting_months'] ?? 0,
                'carry_over_mode' => $figures['carry_over_mode'] ?? 'none',
                'carry_over_years' => $figures['carry_over_years'] ?? 0,
                'max_days_per_request' => $figures['max_days_per_request'] ?? null,
                'max_days_per_year' => null,
                'settings' => isset($figures['settings']) ? json_encode($figures['settings']) : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // The types and figures are the institution's data once the module is in use; the tables go with the first migration.
    }
};
