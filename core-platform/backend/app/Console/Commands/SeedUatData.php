<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ManageDigitalCredentials;
use App\Models\ApiClient;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\JobGrade;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\Profession;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\DataQualityScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A synthetic UAT dataset (ADR 0011) — made-up names and numbers only, never
 * production personal data: a city with sub-cities and woredas, two hierarchy
 * versions, units, job masters, vacant and occupied positions, employees in
 * every stage (draft, submitted, active, transferred, duplicate risk), cards
 * active, lost, replaced and expired, a revoked credential, scoped and
 * city-wide accounts, an API client, a webhook and the data-quality issues
 * the scan then finds. Refused in production and on a database that already
 * holds employees (unless --force on a non-production database).
 */
class SeedUatData extends Command
{
    protected $signature = 'core:seed-uat {--force : Seed even though employees exist (never in production)}';

    protected $description = 'Load a synthetic UAT dataset (no real personal data)';

    public function handle(ManageDigitalCredentials $credentials): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: never in production.');

            return self::FAILURE;
        }
        if (Employee::query()->exists() && ! $this->option('force')) {
            $this->error('Refused: this database already holds employees. Use --force on a throwaway database.');

            return self::FAILURE;
        }
        $password = (string) (env('CORE_UAT_PASSWORD') ?: Str::password(16));
        $accounts = [];

        DB::transaction(function () use ($credentials, $password, &$accounts): void {
            $types = collect(['CITY' => 'City administration', 'SUBCITY' => 'Sub-city administration', 'WOREDA' => 'Woreda administration', 'BUREAU' => 'Bureau'])
                ->map(fn (string $name, string $code) => OrganizationType::query()->firstOrCreate(['code' => 'UAT-'.$code], ['name_en' => $name]));
            $org = fn (string $code, string $type, string $name) => Organization::query()->create([
                'organization_type_id' => $types[$type]->id, 'code' => $code, 'name_en' => $name, 'name_am' => $name.' (አማ)', 'status' => Organization::ACTIVE, 'effective_from' => '2020-01-01',
            ]);
            $city = $org('UAT-CITY', 'CITY', 'UAT City Administration');
            $bureau = $org('UAT-BUR-HEALTH', 'BUREAU', 'UAT Health Bureau');
            $subcities = collect(['A', 'B'])->map(fn (string $s) => $org("UAT-SC-{$s}", 'SUBCITY', "UAT Sub-city {$s}"));
            $woredas = $subcities->flatMap(fn (Organization $sc, int $i) => collect([1, 2])->map(fn (int $w) => $org("UAT-WOR-{$i}{$w}", 'WOREDA', "UAT Woreda {$i}{$w}")));
            $old = HierarchyVersion::query()->create(['version_name' => 'UAT 2025 (archived)', 'status' => HierarchyVersion::ARCHIVED, 'effective_from' => '2025-01-01']);
            $current = HierarchyVersion::current() ?? HierarchyVersion::query()->create(['version_name' => 'UAT 2026', 'status' => HierarchyVersion::PUBLISHED, 'effective_from' => '2026-01-01']);
            $edge = fn (HierarchyVersion $v, Organization $parent, Organization $child) => OrganizationEdge::query()->create([
                'hierarchy_version_id' => $v->id, 'parent_organization_id' => $parent->id, 'child_organization_id' => $child->id, 'relationship_type' => OrganizationEdge::STRUCTURAL,
            ]);
            foreach ([$old, $current] as $version) {
                $edge($version, $city, $bureau);
                $subcities->each(fn (Organization $sc) => $edge($version, $city, $sc));
            }
            $woredas->each(fn (Organization $w, int $i) => $edge($current, $subcities[intdiv($i, 2)], $w));
            $woredas->each(fn (Organization $w) => $edge($old, $subcities[0], $w)); // the old structure had every woreda under sub-city A

            $unitType = OrganizationUnitType::query()->firstOrCreate(['code' => 'uat-directorate'], ['name_en' => 'Directorate']);
            $unit = OrganizationUnit::query()->create(['organization_id' => $bureau->id, 'organization_unit_type_id' => $unitType->id, 'unit_type' => 'directorate', 'code' => 'UAT-DIR-1', 'name_en' => 'UAT Clinical Directorate', 'status' => 'active']);
            $grades = collect(['VIII', 'IX', 'X'])->map(fn (string $g) => JobGrade::query()->firstOrCreate(['code' => 'UAT-'.$g], ['name_en' => 'Grade '.$g]));
            $occupation = Occupation::query()->firstOrCreate(['code' => 'UAT-2221'], ['name_en' => 'Nursing professional']);
            $profession = Profession::query()->firstOrCreate(['code' => 'UAT-NURSE'], ['name_en' => 'Nurse']);
            $position = fn (Organization $o, string $code, string $title) => Position::query()->create([
                'organization_id' => $o->id, 'organization_unit_id' => $o->is($bureau) ? $unit->id : null, 'job_position_code' => $code, 'title_en' => $title,
                'job_grade_id' => $grades->random()->id, 'grade_level' => null, 'occupation_id' => $occupation->id, 'profession_id' => $profession->id,
                'lifecycle_status' => Position::APPROVED, 'effective_from' => '2020-01-01',
            ]);
            $positions = collect(range(1, 12))->map(fn (int $i) => $position($i <= 4 ? $bureau : $woredas[$i % 4], 'UAT-POS-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), $i <= 4 ? 'Nurse' : 'Woreda officer'));
            $position($bureau, 'UAT-POS-VAC', 'Vacant analyst'); // stays vacant

            $number = 0;
            $place = function (Position $p, string $status, string $name) use (&$number): Employee {
                $number++;
                $employee = Employee::query()->create(['employee_number' => 'UAT-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT), 'first_name' => $name, 'last_name' => 'Synthetic',
                    'full_name' => $name.' Synthetic', 'date_of_birth' => '1990-01-'.str_pad((string) (($number % 27) + 1), 2, '0', STR_PAD_LEFT), 'status' => $status,
                    'verification_status' => $status === Employee::ACTIVE ? 'approved' : $status]);
                $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $p->organization_id, 'organization_unit_id' => $p->organization_unit_id,
                    'position_id' => $p->id, 'assignment_status' => $status === Employee::ACTIVE ? EmployeeAssignment::ACTIVE : EmployeeAssignment::PENDING, 'is_current' => true,
                    'effective_from' => '2024-01-01', 'assignment_type' => 'initial', 'source_system' => 'core']);
                $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

                return $employee;
            };
            $active = $positions->take(8)->map(fn (Position $p, int $i) => $place($p, Employee::ACTIVE, 'Active'.$i));
            $place($positions[8], Employee::DRAFT, 'Draft');
            $place($positions[9], Employee::SUBMITTED, 'Submitted');
            $place($positions[10], Employee::VERIFIED, 'Verified');
            $twin = $place($positions[11], Employee::ACTIVE, 'Active0'); // duplicate risk: same name and date of birth as another
            $twin->forceFill(['full_name' => $active[0]->full_name, 'date_of_birth' => $active[0]->date_of_birth])->save();
            // A transferred employee: an ended placement, then the current one.
            $moved = $active[1];
            $closed = EmployeeAssignment::query()->create(['employee_id' => $moved->id, 'organization_id' => $woredas[0]->id, 'position_id' => null, 'assignment_status' => EmployeeAssignment::CLOSED,
                'is_current' => false, 'effective_from' => '2022-01-01', 'effective_to' => '2023-12-31', 'assignment_type' => 'initial', 'source_system' => 'core']);
            EmployeeAssignment::query()->whereKey($moved->current_assignment_id)->update(['assignment_type' => 'transfer', 'previous_assignment_id' => $closed->id]);

            $cardNumber = 0;
            $card = function (Employee $e, string $status, ?string $expires = null) use (&$cardNumber, $credentials): IdCard {
                $cardNumber++;
                $card = IdCard::query()->create(['employee_id' => $e->id, 'card_number' => 'UAT-CARD-'.str_pad((string) $cardNumber, 5, '0', STR_PAD_LEFT), 'status' => $status,
                    'is_current' => ! in_array($status, [IdCard::REPLACED], true), 'public_card_uuid' => (string) Str::uuid(), 'qr_status' => 'active',
                    'issued_at' => now()->subMonths(6), 'expires_at' => $expires ?? now()->addYear(), 'qc_status' => 'passed', 'print_count' => 1]);
                $credentials->issue($card, null);

                return $card;
            };
            $card($active[0], IdCard::ACTIVE);
            $card($active[2], IdCard::ACTIVE);
            $card($active[3], IdCard::LOST);
            $replaced = $card($active[4], IdCard::REPLACED);
            $card($active[4], IdCard::ACTIVE)->forceFill(['previous_card_id' => $replaced->id])->save();
            $card($active[5], IdCard::ACTIVE, now()->subDay()->toDateTimeString()); // expired but still marked active
            $revoked = $card($active[6], IdCard::ACTIVE);
            $credentials->revokeForCard($revoked, 'UAT: revoked credential on an active card', null); // a data-quality finding

            $account = function (string $name, string $role, ?Organization $scope) use ($password, &$accounts): void {
                $email = 'uat.'.Str::slug($name).'@core.test';
                User::query()->create(['name' => 'UAT '.$name, 'email' => $email, 'password' => $password, 'role' => $role, 'must_change_password' => false,
                    'scope_organization_id' => $scope?->id, 'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true]);
                $accounts[] = [$email, $role, $scope?->code ?? 'city-wide'];
            };
            $account('City Admin', 'core_admin', null);
            $account('City Admin Two', 'core_admin', null);
            $account('Bureau Officer', 'core_officer', $bureau);
            $account('Bureau Verifier', 'core_officer', $bureau);
            $account('Sub-city A Officer', 'core_officer', $subcities[0]);
            $account('Viewer', 'core_viewer', $subcities[1]);

            $client = new ApiClient(['name' => 'UAT Cafeteria', 'scopes' => ['cards:verify', 'employees:basic-read'], 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
            $client->save();
            $webhook = new WebhookEndpoint(['name' => 'UAT receiver', 'url' => 'https://uat-receiver.test/hooks', 'event_types' => ['*'], 'is_active' => false]);
            $webhook->forceFill(['secret' => 'whsec_'.Str::random(40)])->save();
        });

        $scan = app(DataQualityScanner::class)->scan();
        $this->table(['Account', 'Role', 'Scope'], $accounts);
        $this->info(env('CORE_UAT_PASSWORD') ? 'Passwords: CORE_UAT_PASSWORD.' : 'Password for every UAT account (shown once): '.$password);
        $this->info('Data-quality issues opened by the scan: '.array_sum(array_column($scan, 'opened')).'. The API client has no secret yet: rotate it in the Integration Center.');

        return self::SUCCESS;
    }
}
