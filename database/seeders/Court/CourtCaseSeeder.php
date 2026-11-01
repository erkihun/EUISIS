<?php

namespace Database\Seeders\Court;

use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CourtCaseSeeder extends Seeder
{
    public function run(): void
    {
        // Fabricated cases must never be mixed into a live case register.
        if (app()->environment('production')) {
            $this->command?->warn('Skipping CourtCaseSeeder: sample cases are not seeded in production.');

            return;
        }

        $schema = DB::getSchemaBuilder();
        foreach (['court_cases', 'case_types'] as $table) {
            if (! $schema->hasTable($table)) {
                $this->command?->warn("Skipping CourtCaseSeeder: missing table '{$table}'.");
                return;
            }
        }

        $types = DB::table('court_case_types')->pluck('id')->all();
        if (count($types) === 0) {
            $seedTypes = ['Civil', 'Criminal', 'Labor', 'Commercial', 'Family'];
            foreach ($seedTypes as $name) {
                DB::table('court_case_types')->insert([
                    'name' => $name,
                    'description' => $name . ' case',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $types = DB::table('court_case_types')->pluck('id')->all();
        }

        $faker = Faker::create();
        $statuses = ['pending', 'active', 'adjourned', 'dismissed', 'closed'];
        $columns = array_flip(Schema::getColumnListing('court_cases'));
        $applicantIds = $schema->hasTable('court_applicants')
            ? DB::table('court_applicants')->pluck('id')->all()
            : [];
        $judgeIds = $this->resolveJudgeIds();

        $rows = [];
        $usedCodes = [];
        $year = now()->format('Y');

        for ($i = 1; $i <= 20; $i++) {
            $caseNumber = sprintf('CC-%s-%04d', $year, $i);
            $code = strtoupper(Str::random(8));
            while (isset($usedCodes[$code])) {
                $code = strtoupper(Str::random(8));
            }
            $usedCodes[$code] = true;

            $filingDate = $faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d');

            $row = [
                'applicant_id' => count($applicantIds) ? $faker->randomElement($applicantIds) : null,
                'case_number' => $caseNumber,
                'code' => $code,
                'title' => 'Sample Case ' . $i,
                'respondent_name' => $faker->name,
                'respondent_address' => $faker->address,
                'description' => $faker->paragraph,
                'relief_requested' => $faker->sentence,
                'case_type_id' => $faker->randomElement($types),
                'judge_id' => count($judgeIds) ? $faker->randomElement($judgeIds) : null,
                'filing_date' => $filingDate,
                'first_hearing_date' => null,
                'status' => $faker->randomElement($statuses),
                'assigned_user_id' => null,
                'assigned_at' => null,
                'notes' => $faker->sentence,
                'review_status' => 'awaiting_review',
                'review_note' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $rows[] = array_intersect_key($row, $columns);
        }

        DB::table('court_cases')->insert($rows);
        $this->command?->info('Seeded 20 sample court cases.');
    }

    private function resolveJudgeIds(): array
    {
        $schema = DB::getSchemaBuilder();
        if (! $schema->hasTable('court_staff')) {
            return [];
        }

        if (Schema::hasColumn('court_staff', 'user_type')) {
            $ids = DB::table('court_staff')->where('user_type', 'judge')->pluck('id')->all();
            if (count($ids)) {
                return $ids;
            }
        }

        if ($schema->hasTable('court_roles') && $schema->hasTable('court_role_user')) {
            $roleId = DB::table('court_roles')->where('name', 'judge')->value('id');
            if ($roleId) {
                return DB::table('court_role_user')->where('role_id', $roleId)->pluck('user_id')->all();
            }
        }

        return [];
    }
}
