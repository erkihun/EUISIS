<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public Tip-off (ጥቆማ) — additive only.
 *
 * A Tip-off is still a `suggestion` row. This adds what the paper Tip-off
 * intake form carries and the digital form now asks for: the tip type, how
 * the tip arrived, the optional tipster details (e-mail and address, both
 * encrypted at rest by the model), other persons or offices involved, and —
 * for Feedback and Complaint — the submitter's optional organization.
 *
 * Every new column is nullable. No existing suggestion is given a tip type:
 * the old rows were not classified, and inventing a class would falsify them.
 * intake_channel gets WEB_QR as its default because every existing public
 * submission did arrive through the public QR pages.
 *
 * Routing rules gain an optional tip type, so an institution can route
 * corruption tips to a specifically designated handler.
 */
return new class extends Migration
{
    private const TABLE = 'employee_service_feedback';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('tip_type', 32)->nullable();
            $table->string('intake_channel', 16)->nullable()->default('WEB_QR');
            $table->text('client_email')->nullable();
            $table->string('submitter_organization', 200)->nullable();
            $table->text('submitter_address')->nullable();
            $table->text('involved_parties')->nullable();

            $table->index(['type', 'tip_type', 'submitted_at'], 'esf_type_tip_submitted_idx');
        });

        Schema::table('public_feedback_routing_rules', function (Blueprint $table): void {
            $table->string('tip_type', 32)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT esf_tip_type_chk CHECK (tip_type IS NULL OR (type = 'suggestion' AND tip_type IN ('CORRUPTION', 'IMPROPER_PRACTICE')))");
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT esf_intake_channel_chk CHECK (intake_channel IS NULL OR intake_channel IN ('WEB_QR', 'PHONE', 'ORAL', 'WRITTEN'))");
            DB::statement("ALTER TABLE public_feedback_routing_rules ADD CONSTRAINT pfrr_tip_type_chk CHECK (tip_type IS NULL OR tip_type IN ('CORRUPTION', 'IMPROPER_PRACTICE'))");
        }

        if (DB::pretending()) {
            return;
        }

        CodeRule::query()->firstOrCreate(
            ['active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::PublicTipOff)],
            [
                'entity_type' => CodeRuleEntityType::PublicTipOff,
                'name_en' => 'Public Tip-off Reference',
                'name_am' => 'የሕዝብ ጥቆማ መለያ ቁጥር',
                'prefix' => 'PTO',
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
        $hasData = DB::table(self::TABLE)->where(fn ($q) => $q
            ->whereNotNull('tip_type')
            ->orWhereNotNull('client_email')
            ->orWhereNotNull('submitter_organization')
            ->orWhereNotNull('submitter_address')
            ->orWhereNotNull('involved_parties'))->exists();

        if ($hasData || DB::table('public_feedback_routing_rules')->whereNotNull('tip_type')->exists()) {
            throw new RuntimeException('Tip-off data exists; export or migrate it before rolling back the Tip-off columns.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS esf_tip_type_chk');
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS esf_intake_channel_chk');
            DB::statement('ALTER TABLE public_feedback_routing_rules DROP CONSTRAINT IF EXISTS pfrr_tip_type_chk');
        }

        Schema::table('public_feedback_routing_rules', function (Blueprint $table): void {
            $table->dropColumn('tip_type');
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex('esf_type_tip_submitted_idx');
            $table->dropColumn(['tip_type', 'intake_channel', 'client_email', 'submitter_organization', 'submitter_address', 'involved_parties']);
        });

        // The reference code rule and any references it issued are kept.
    }
};
