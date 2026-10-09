<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CafeteriaGrantStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaSubsidyRule;
use App\Models\CafeteriaTransaction;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaSettingsService;
use App\Services\Cafeteria\Policy\CafeteriaPolicyWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Classifies what the old global cafeteria configuration becomes in the
 * policy architecture (docs/cafeteria-policy-architecture.md → Migration).
 *
 * Nothing financial is guessed. A global or employee-type subsidy rule cannot
 * say which organization it was meant for: it is NEEDS_DECISION. An
 * organization-specific rule may be turned into a DRAFT policy (--create-drafts)
 * for an administrator to review and approve — never into an active one.
 */
class CafeteriaLegacyPolicyReport extends Command
{
    protected $signature = 'cafeteria:legacy-report {--create-drafts : Draft (not approve) policies for organization-specific legacy subsidy rules} {--actor= : User id recorded as the drafter}';

    protected $description = 'Classify legacy cafeteria settings, subsidy rules and locations for the policy architecture';

    /** @var array<string, string> setting key => classification */
    private const SETTINGS = [
        'default_daily_subsidy_amount' => 'OBSOLETE (financial; never a fallback)',
        'currency' => 'SYSTEM_DEFAULT',
        'week_start_day' => 'SYSTEM_DEFAULT (week window)',
        'week_end_day' => 'SYSTEM_DEFAULT (week window)',
        'default_usage_mode' => 'SYSTEM_DEFAULT (scanner default)',
        'allow_upfront_weekday_usage' => 'SYSTEM_DEFAULT → prefills ORGANIZATION_POLICY.allow_advance_usage',
        'allow_past_day_claim' => 'SYSTEM_DEFAULT (fixed rule)',
        'allow_future_week_borrowing' => 'SYSTEM_DEFAULT (fixed rule)',
        'exclude_public_holidays' => 'SYSTEM_DEFAULT → prefills ORGANIZATION_POLICY.exclude_public_holidays',
        'closed_weekend_default' => 'CAFETERIA_OPERATIONAL (physical weekend default)',
        'allow_saturday_service' => 'CAFETERIA_OPERATIONAL (physical weekend default)',
        'allow_sunday_service' => 'CAFETERIA_OPERATIONAL (physical weekend default)',
        'weekend_scan_mode' => 'SYSTEM_DEFAULT (paid-service fallback)',
        'holiday_scan_mode' => 'SYSTEM_DEFAULT (paid-service fallback)',
        'excess_amount_mode' => 'SYSTEM_DEFAULT → prefills ORGANIZATION_POLICY.extra_scan_policy',
        'require_active_employee' => 'SYSTEM_DEFAULT (fixed rule)',
        'require_active_id_card' => 'SYSTEM_DEFAULT (fixed rule)',
        'require_provider_operator' => 'PROVIDER_OPERATIONAL',
        'max_transaction_amount_per_scan' => 'SYSTEM_DEFAULT (safety limit)',
        'max_extra_amount_per_week' => 'SYSTEM_DEFAULT (safety limit)',
        'payroll_cutoff_day' => 'OBSOLETE (no consumer)',
        'report_default_format' => 'SYSTEM_DEFAULT (report)',
        'report_timezone' => 'SYSTEM_DEFAULT (report)',
        'block_cafeteria_during_employee_leave' => 'SYSTEM_DEFAULT → prefills ORGANIZATION_POLICY.block_employee_leave',
        'leave_scan_mode' => 'SYSTEM_DEFAULT (paid-service fallback)',
        'exclude_leave_days_from_subsidy' => 'SYSTEM_DEFAULT',
        'allow_leave_day_retroactive_claim' => 'SYSTEM_DEFAULT (fixed rule)',
        'auto_resume_after_leave' => 'SYSTEM_DEFAULT (fixed rule)',
    ];

    public function handle(CafeteriaSettingsService $settings, CafeteriaPolicyWorkflowService $workflow): int
    {
        $this->info('Cafeteria settings');
        $values = $settings->all();
        $this->table(['Setting', 'Current value', 'Classification'], collect(self::SETTINGS)->map(fn (string $class, string $key): array => [
            $key, json_encode($values[$key] ?? null), $class,
        ])->values()->all());

        $actor = $this->option('actor') !== null ? User::query()->find($this->option('actor')) : null;
        if ($this->option('create-drafts') && $actor === null) {
            $this->error('--create-drafts needs --actor=<user id> to record who drafted the policies.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Legacy subsidy rules');
        $rows = [];
        CafeteriaSubsidyRule::query()->orderBy('effective_from')->get()->each(function (CafeteriaSubsidyRule $rule) use (&$rows, $actor, $workflow): void {
            $status = match (true) {
                ! $rule->is_active => 'INACTIVE (history only)',
                $rule->applies_to !== 'organization' || $rule->organization_id === null => 'NEEDS_DECISION: applies to every organization — choose the organizations and create their policies',
                default => null,
            };

            if ($status === null) {
                $assignments = CafeteriaServiceAssignment::query()
                    ->where('organization_id', $rule->organization_id)
                    ->where('status', CafeteriaGrantStatus::Active->value)
                    ->get();

                if ($assignments->isEmpty()) {
                    $status = 'NEEDS_DECISION: no active service assignment for this organization';
                } elseif ($this->option('create-drafts')) {
                    $created = $assignments->filter(fn (CafeteriaServiceAssignment $assignment): bool => ! CafeteriaServicePolicy::query()
                        ->where('cafeteria_service_assignment_id', $assignment->id)->exists())
                        ->map(fn (CafeteriaServiceAssignment $assignment) => DB::transaction(fn () => $workflow->createDraft([
                            'cafeteria_service_assignment_id' => $assignment->id,
                            'daily_subsidy_amount' => (string) $rule->subsidy_amount,
                            'employee_contribution_amount' => '0.00',
                            'provider_price' => (string) $rule->subsidy_amount,
                            'currency_code' => $rule->currency ?: 'ETB',
                            'monday_enabled' => true, 'tuesday_enabled' => true, 'wednesday_enabled' => true,
                            'thursday_enabled' => true, 'friday_enabled' => true,
                            'saturday_enabled' => ! $rule->exclude_weekends, 'sunday_enabled' => false,
                            'effective_from' => max($rule->effective_from->toDateString(), $assignment->effective_from->toDateString()),
                            'effective_to' => $rule->effective_to?->toDateString(),
                            'notes' => "Drafted from legacy subsidy rule {$rule->code}. Review price and contribution before approval.",
                        ], $actor)));
                    $status = 'DRAFTED '.$created->count().' policy draft(s) — review, then approve';
                } else {
                    $status = 'MAPPABLE: run with --create-drafts to draft policies for review';
                }
            }

            $rows[] = [$rule->code, $rule->applies_to, $rule->organization_id ?? '—', (string) $rule->subsidy_amount, $rule->effective_from?->toDateString(), $status];
        });
        $this->table(['Rule', 'Applies to', 'Organization', 'Subsidy', 'From', 'Result'], $rows);

        $this->newLine();
        $this->info('Cafeteria locations');
        $unplaced = CafeteriaProvider::query()->where(fn ($q) => $q->whereNull('provider_id')->orWhereNull('cafeteria_service_network_id'))->get(['code', 'name_en', 'provider_id', 'cafeteria_service_network_id']);
        $this->line($unplaced->isEmpty() ? 'Every cafeteria belongs to a provider and a network.' : 'NEEDS_DECISION: these cafeterias need a provider and a network before they can serve:');
        foreach ($unplaced as $cafeteria) {
            $this->line("  - {$cafeteria->code} {$cafeteria->name_en}".($cafeteria->provider_id ? '' : ' (no provider)').($cafeteria->cafeteria_service_network_id ? '' : ' (no network)'));
        }

        $legacyBranches = DB::table('cafeteria_provider_branches')->whereNull('deleted_at')->count();
        if ($legacyBranches > 0) {
            $this->line("NEEDS_DECISION: {$legacyBranches} legacy branch record(s) in cafeteria_provider_branches; add them as branch locations of their network.");
        }

        $this->newLine();
        $unattributed = CafeteriaTransaction::query()->where('pricing_source', 'legacy')->whereNull('employee_organization_id')->count();
        $this->line("Legacy transactions: {$unattributed} without a determinable billing organization (kept with their stored amounts; shown as unattributed in settlements).");

        return self::SUCCESS;
    }
}
