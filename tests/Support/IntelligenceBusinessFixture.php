<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Position;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\IntelligenceService;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use App\Services\SituationRoom\SituationContext;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builders for the business intelligence tests on top of the Situation Room
 * fixture (SituationRoomScenario): the grants every viewer needs, a context
 * built the way the controller builds it, and the rows that make the
 * workforce, EPMS and transfer figures distinct and known.
 */
final class IntelligenceBusinessFixture
{
    /** The grants a business viewer carries besides the area grant(s) under test. */
    public static function viewerGrants(): array
    {
        return [IntelligencePermissions::VIEW, PublicFeedbackVisibility::EMPLOYEE_TARGET, PublicFeedbackVisibility::ORGANIZATION_TARGET];
    }

    public static function createPermissions(): void
    {
        foreach ([...IntelligencePermissions::all(), PublicFeedbackVisibility::EMPLOYEE_TARGET, PublicFeedbackVisibility::ORGANIZATION_TARGET] as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    /** @param  array<string, string>  $query */
    public static function context(User $user, array $query = []): SituationContext
    {
        Cache::flush();

        return app(IntelligenceService::class)->context($user, Request::create('/intelligence/overview', 'GET', $query));
    }

    /**
     * The area payload for a viewer, uncached.
     *
     * @param  array<string, string>  $query
     * @return array{panels: list<array<string, mixed>>, insights: list<array<string, mixed>>, has_errors: bool}
     */
    public static function area(IntelligenceArea $area, User $user, array $query = []): array
    {
        return app(IntelligenceService::class)->area($area, self::context($user, $query))['payload'];
    }

    /** @param  list<array<string, mixed>>  $panels */
    public static function panel(array $panels, string $key): ?array
    {
        foreach ($panels as $panel) {
            if ($panel['key'] === $key) {
                return $panel;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $panel */
    public static function kpi(array $panel, string $key): mixed
    {
        foreach ($panel['data']['items'] ?? [] as $item) {
            if ($item['key'] === $key) {
                return $item['value'];
            }
        }

        throw new \RuntimeException("KPI [{$key}] not in panel [{$panel['key']}].");
    }

    /** @param  list<array<string, mixed>>  $insights */
    public static function insight(array $insights, string $key): ?array
    {
        foreach ($insights as $insight) {
            if ($insight['key'] === $key) {
                return $insight;
            }
        }

        return null;
    }

    public static function setting(string $group, string $key, string $value, string $type = 'integer'): void
    {
        SystemSetting::query()->updateOrCreate(['group' => $group, 'key' => $key], ['value' => $value, 'type' => $type]);
        app(SystemSettingsService::class)->clearCache();
        Cache::flush();
    }

    public static function establishment(Position $position, int $slots): string
    {
        $id = (string) Str::uuid();
        DB::table('position_establishments')->insert([
            'id' => $id, 'establishment_number' => 'EST-'.substr($id, 0, 8), 'organization_id' => $position->organization_id,
            'position_id' => $position->id, 'approved_slots' => $slots, 'effective_from' => now()->subYear()->toDateString(),
            'status' => 'approved', 'approved_at' => now()->subYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** An approved manpower plan with one approved requirement. */
    public static function approvedPlan(Organization $organization, float $requiredFte, int $approvedHeadcount): void
    {
        $methodology = (string) Str::uuid();
        DB::table('workforce_planning_methodologies')->insert([
            'id' => $methodology, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'M-'.substr($methodology, 0, 6), 'name_en' => 'Ratio',
            'calculation_type' => 'RATIO', 'status' => 'published', 'effective_from' => now()->subYear()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $plan = (string) Str::uuid();
        DB::table('manpower_plans')->insert([
            'id' => $plan, 'reference_number' => 'MPP-'.substr($plan, 0, 8), 'organization_id' => $organization->id, 'methodology_id' => $methodology,
            'title_en' => 'Plan', 'planning_period_start' => now()->startOfYear()->toDateString(), 'planning_period_end' => now()->endOfYear()->toDateString(),
            'status' => 'approved', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $occupation = DB::table('occupations')->value('id') ?? self::occupation();
        DB::table('position_requirements')->insert([
            'id' => (string) Str::uuid(), 'plan_id' => $plan, 'organization_id' => $organization->id, 'occupation_id' => $occupation,
            'position_title_en' => 'Officer', 'position_type' => 'permanent', 'required_fte' => $requiredFte, 'approved_headcount' => $approvedHeadcount,
            'status' => 'approved', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function occupation(): string
    {
        $id = (string) Str::uuid();
        DB::table('occupations')->insert(['id' => $id, 'code' => 'OCC-'.substr($id, 0, 6), 'name_en' => 'Clerk', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** A current cycle whose planning phase ended yesterday. */
    public static function currentCycle(bool $planningEnded = true): string
    {
        $id = (string) Str::uuid();
        DB::table('performance_cycles')->insert([
            'id' => $id, 'code' => 'CY-'.substr($id, 0, 6), 'name_en' => 'Cycle '.substr($id, 0, 4), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'planning_start_date' => now()->startOfYear()->toDateString(),
            'planning_end_date' => $planningEnded ? now()->subDay()->toDateString() : now()->addMonth()->toDateString(),
            'status' => 'ACTIVE', 'is_current' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public static function agreement(string $cycleId, Employee $employee, Organization $organization, string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('employee_performance_agreements')->insert([
            'id' => $id, 'cycle_id' => $cycleId, 'employee_id' => $employee->id, 'employee_assignment_id' => $employee->current_assignment_id,
            'organization_id' => $organization->id, 'agreement_version' => 1, 'is_temporary' => false, 'status' => $status,
            'effective_from' => now()->startOfYear()->toDateString(), 'effective_to' => now()->endOfYear()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public static function completedTransfer(Employee $employee, Organization $from, Organization $to, \DateTimeInterface $completedAt, User $requestedBy): void
    {
        DB::table('employee_transfers')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee->id, 'from_organization_id' => $from->id, 'to_organization_id' => $to->id,
            'current_assignment_id' => $employee->current_assignment_id, 'requested_by' => $requestedBy->id, 'effective_date' => $completedAt->format('Y-m-d'),
            'status' => 'completed', 'completed_at' => $completedAt, 'transfer_source' => 'manual', 'created_at' => $completedAt, 'updated_at' => $completedAt,
        ]);
    }
}
