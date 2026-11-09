<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Entitlement ledger + historical transaction snapshots
 * (docs/cafeteria-entitlement-rules.md, docs/cafeteria-settlement-rules.md).
 *
 * `cafeteria_transaction_consumed_days` becomes the entitlement ledger. Its
 * `active_key` ("employee|date|type|slot") is UNIQUE and set only while the
 * entitlement is consumed, so the database itself refuses a second use of the
 * same entitlement at any cafeteria — with no partial index, which MySQL lacks.
 * A reversal clears the key and frees the entitlement again.
 *
 * Legacy rows keep their stored money. Nothing is recalculated from a policy:
 * policy columns stay NULL and `pricing_source` says `legacy`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafeteria_transactions', function (Blueprint $table): void {
            $table->uuid('employee_assignment_id')->nullable();
            $table->uuid('employee_organization_id')->nullable();
            $table->uuid('cafeteria_service_network_id')->nullable();
            $table->uuid('provider_id')->nullable();
            $table->uuid('cafeteria_service_assignment_id')->nullable();
            $table->uuid('cafeteria_service_policy_id')->nullable();
            $table->unsignedInteger('cafeteria_policy_version')->nullable();
            $table->decimal('employee_contribution_applied', 12, 2)->nullable();
            $table->decimal('provider_price_applied', 12, 2)->nullable();
            $table->decimal('total_amount_applied', 12, 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('pricing_source', 20)->default('legacy');
            $table->json('policy_snapshot')->nullable();
            $table->uuid('service_terminal_id')->nullable();

            $table->foreign('employee_assignment_id', 'ct_emp_assignment_fk')->references('id')->on('employee_assignments')->nullOnDelete();
            $table->foreign('employee_organization_id', 'ct_emp_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('cafeteria_service_network_id', 'ct_network_fk')->references('id')->on('cafeteria_service_networks')->restrictOnDelete();
            $table->foreign('provider_id', 'ct_payee_fk')->references('id')->on('providers')->restrictOnDelete();
            $table->foreign('cafeteria_service_assignment_id', 'ct_service_assignment_fk')->references('id')->on('cafeteria_service_assignments')->restrictOnDelete();
            $table->foreign('cafeteria_service_policy_id', 'ct_policy_fk')->references('id')->on('cafeteria_service_policies')->restrictOnDelete();
            $table->foreign('service_terminal_id', 'ct_terminal_fk')->references('id')->on('service_terminals')->nullOnDelete();

            $table->index(['employee_organization_id', 'transaction_date'], 'ct_emp_org_date_idx');
            $table->index(['provider_id', 'transaction_date'], 'ct_payee_date_idx');
            $table->index('cafeteria_service_policy_id', 'ct_policy_idx');
        });

        Schema::table('cafeteria_transaction_consumed_days', function (Blueprint $table): void {
            $table->uuid('organization_id')->nullable();
            $table->string('entitlement_type', 20)->default('meal');
            $table->unsignedTinyInteger('slot_no')->default(1);
            $table->uuid('cafeteria_service_network_id')->nullable();
            $table->uuid('consumed_at_cafeteria_id')->nullable();
            $table->uuid('provider_id')->nullable();
            $table->string('usage_type', 20)->nullable();
            $table->string('status', 20)->default('consumed');
            $table->string('active_key', 120)->nullable();

            $table->unique('active_key', 'ctcd_active_entitlement_unique');
            $table->index(['organization_id', 'consumed_date'], 'ctcd_org_date_idx');
            $table->foreign('organization_id', 'ctcd_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('consumed_at_cafeteria_id', 'ctcd_cafeteria_fk')->references('id')->on('cafeteria_providers')->restrictOnDelete();
        });

        $this->backfillTransactions();
        $this->backfillEntitlementLedger();
    }

    public function down(): void
    {
        Schema::table('cafeteria_transaction_consumed_days', function (Blueprint $table): void {
            $table->dropForeign('ctcd_org_fk');
            $table->dropForeign('ctcd_cafeteria_fk');
            $table->dropUnique('ctcd_active_entitlement_unique');
            $table->dropIndex('ctcd_org_date_idx');
            $table->dropColumn([
                'organization_id', 'entitlement_type', 'slot_no', 'cafeteria_service_network_id',
                'consumed_at_cafeteria_id', 'provider_id', 'usage_type', 'status', 'active_key',
            ]);
        });

        Schema::table('cafeteria_transactions', function (Blueprint $table): void {
            foreach (['ct_emp_assignment_fk', 'ct_emp_org_fk', 'ct_network_fk', 'ct_payee_fk', 'ct_service_assignment_fk', 'ct_policy_fk', 'ct_terminal_fk'] as $foreign) {
                $table->dropForeign($foreign);
            }
            $table->dropIndex('ct_emp_org_date_idx');
            $table->dropIndex('ct_payee_date_idx');
            $table->dropIndex('ct_policy_idx');
            $table->dropColumn([
                'employee_assignment_id', 'employee_organization_id', 'cafeteria_service_network_id', 'provider_id',
                'cafeteria_service_assignment_id', 'cafeteria_service_policy_id', 'cafeteria_policy_version',
                'employee_contribution_applied', 'provider_price_applied', 'total_amount_applied', 'currency_code',
                'pricing_source', 'policy_snapshot', 'service_terminal_id',
            ]);
        });
    }

    /**
     * Payee and billing organization for legacy rows, from facts only: the
     * cafeteria's provider, and the ONE assignment effective on the
     * transaction date. Ambiguous or missing history stays NULL (reported as
     * unattributed) rather than guessed from today's assignment.
     */
    private function backfillTransactions(): void
    {
        DB::table('cafeteria_transactions')
            ->select(['id', 'employee_id', 'cafeteria_provider_id', 'transaction_date'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                $payees = DB::table('cafeteria_providers')
                    ->whereIn('id', $rows->pluck('cafeteria_provider_id')->filter()->unique()->all())
                    ->pluck('provider_id', 'id');

                foreach ($rows as $row) {
                    $date = substr((string) $row->transaction_date, 0, 10);
                    $assignments = $row->employee_id === null || $date === '' ? collect() : DB::table('employee_assignments')
                        ->where('employee_id', $row->employee_id)
                        ->whereDate('effective_from', '<=', $date)
                        ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                        ->get(['id', 'organization_id']);

                    $organizations = $assignments->pluck('organization_id')->unique();

                    DB::table('cafeteria_transactions')->where('id', $row->id)->update([
                        'provider_id' => $payees[$row->cafeteria_provider_id] ?? null,
                        'employee_organization_id' => $organizations->count() === 1 ? $organizations->first() : null,
                        'employee_assignment_id' => $assignments->count() === 1 ? $assignments->first()->id : null,
                        'pricing_source' => 'legacy',
                    ]);
                }
            });
    }

    /**
     * The earliest un-reversed row of each employee/date keeps the
     * entitlement. Any later un-reversed row for the same day is historical
     * double use: kept, flagged `legacy_duplicate`, but holding no key.
     */
    private function backfillEntitlementLedger(): void
    {
        // Rows arrive grouped by employee and date, so only the key claimed
        // last can repeat.
        $claimed = null;

        DB::table('cafeteria_transaction_consumed_days as d')
            ->leftJoin('cafeteria_transactions as t', 't.id', '=', 'd.cafeteria_transaction_id')
            ->leftJoin('cafeteria_providers as c', 'c.id', '=', 't.cafeteria_provider_id')
            ->orderBy('d.employee_id')
            ->orderBy('d.consumed_date')
            ->orderBy('d.created_at')
            ->orderBy('d.id')
            ->select([
                'd.id', 'd.employee_id', 'd.consumed_date', 'd.reversed_at',
                't.cafeteria_provider_id', 't.transaction_date', 't.employee_organization_id', 'c.provider_id',
            ])
            ->lazy(500)
            ->each(function ($row) use (&$claimed): void {
                $date = substr((string) $row->consumed_date, 0, 10);
                $key = "{$row->employee_id}|{$date}|meal|1";
                $status = 'reversed';
                $activeKey = null;

                if ($row->reversed_at === null) {
                    if ($claimed === $key) {
                        $status = 'legacy_duplicate';
                    } else {
                        $claimed = $key;
                        $status = 'consumed';
                        $activeKey = $key;
                    }
                }

                DB::table('cafeteria_transaction_consumed_days')->where('id', $row->id)->update([
                    'organization_id' => $row->employee_organization_id,
                    'consumed_at_cafeteria_id' => $row->cafeteria_provider_id,
                    'provider_id' => $row->provider_id,
                    'usage_type' => $date === substr((string) $row->transaction_date, 0, 10) ? 'service_day' : 'advance',
                    'status' => $status,
                    'active_key' => $activeKey,
                ]);
            });
    }
};
