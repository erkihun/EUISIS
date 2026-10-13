<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, phase 11: indexes for the
 * columns the reports, gap checks and mapping workspace filter on.
 * PostgreSQL does not index foreign-key columns by itself; an audit of
 * pg_indexes against the foreign keys of the domain's tables found these
 * organization-scoped filters served by sequential scans. Additive only.
 */
return new class extends Migration
{
    /** @var array<string, array{0: list<string>, 1: string}> */
    private const INDEXES = [
        'process_activities' => [['organization_id'], 'process_activities_org_idx'],
        'process_tasks' => [['organization_id', 'is_active'], 'process_tasks_org_active_idx'],
        'work_occupation_requirements' => [['organization_id', 'task_id'], 'work_occupation_requirements_org_task_idx'],
        'work_competency_requirements' => [['organization_id', 'task_id'], 'work_competency_requirements_org_task_idx'],
        'work_qualification_requirements' => [['organization_id', 'task_id'], 'work_qualification_requirements_org_task_idx'],
        'unit_design_proposals' => [['organization_id'], 'unit_design_proposals_org_idx'],
        'manpower_plan_lines' => [['organization_id'], 'manpower_plan_lines_org_idx'],
        'position_requirements' => [['organization_id'], 'position_requirements_org_idx'],
        'position_profiles' => [['organization_id', 'status'], 'position_profiles_org_status_idx'],
        'position_work_links' => [['organization_id', 'position_id'], 'position_work_links_org_position_idx'],
        'position_competency_requirements' => [['organization_id'], 'pcr_org_idx'],
        'position_qualification_requirements' => [['organization_id'], 'pqr_org_idx'],
        'job_descriptions' => [['position_profile_id'], 'job_descriptions_profile_idx'],
        'organization_design_report_exports' => [['organization_id'], 'od_report_exports_org_idx'],
        'position_service_tasks' => [['organization_id', 'master_task_id'], 'position_service_tasks_org_master_idx'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$columns, $name]) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($columns, $name): void {
                $t->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [, $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, function (Blueprint $t) use ($name): void {
                    $t->dropIndex($name);
                });
            }
        }
    }
};
