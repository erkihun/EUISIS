<?php

declare(strict_types=1);

use App\Support\CardTemplateConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * P3 (ADR 0010): card production.
 *
 *  - card_templates and their versions: a version is a draft, then active,
 *    then retired; its layout is bounded settings (no markup). Exactly one
 *    version is active at a time (a partial unique index; a generated column
 *    on MySQL). Each printed card keeps the version it was printed with.
 *  - card_print_batches and their items: a numbered batch of cards printed
 *    together; an item is a first print or a reprint (with its reason), may
 *    be spoiled, and has its own quality check.
 *  - id_cards: the template version, the quality-check result (who, when,
 *    notes), the print count and the batch it is in now.
 *
 * Existing cards: a card printed before P3 has a print count of 1; one still
 * waiting to be issued must pass a quality check first (pending); cards
 * already issued keep "not recorded" — nothing is invented about them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $partial = in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true);

        Schema::create('card_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('card_template_versions', function (Blueprint $table) use ($partial): void {
            $table->uuid('id')->primary();
            $table->uuid('card_template_id');
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->json('config');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('retired_by')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            if (! $partial) {
                $table->unsignedTinyInteger('active_flag')->nullable()->virtualAs("CASE WHEN status = 'active' THEN 1 END");
                $table->unique(['active_flag'], 'card_template_versions_one_active');
            }
            $table->unique(['card_template_id', 'version'], 'card_template_versions_number_unique');
            $table->foreign('card_template_id')->references('id')->on('card_templates')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('activated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('retired_by')->references('id')->on('users')->nullOnDelete();
        });
        if ($partial) {
            DB::statement("CREATE UNIQUE INDEX card_template_versions_one_active ON card_template_versions (status) WHERE status = 'active'");
        }

        Schema::create('card_print_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('batch_number', 64)->unique();
            $table->string('status', 16)->default('draft');
            $table->uuid('card_template_version_id');
            $table->text('printer_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('printed_by')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status'], 'card_print_batches_status_idx');
            $table->foreign('card_template_version_id')->references('id')->on('card_template_versions')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('printed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('card_print_batch_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('card_print_batch_id');
            $table->uuid('id_card_id');
            $table->string('kind', 16)->default('print');
            $table->text('reprint_reason')->nullable();
            $table->string('status', 16)->default('queued');
            $table->string('qc_status', 16)->default('pending');
            $table->unsignedBigInteger('qc_by')->nullable();
            $table->timestamp('qc_at')->nullable();
            $table->text('qc_notes')->nullable();
            $table->timestamps();
            $table->unique(['card_print_batch_id', 'id_card_id'], 'card_print_batch_items_card_unique');
            $table->index(['id_card_id'], 'card_print_batch_items_card_idx');
            $table->foreign('card_print_batch_id')->references('id')->on('card_print_batches')->restrictOnDelete();
            $table->foreign('id_card_id')->references('id')->on('id_cards')->restrictOnDelete();
            $table->foreign('qc_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('id_cards', function (Blueprint $table): void {
            $table->uuid('card_template_version_id')->nullable();
            $table->string('qc_status', 16)->default('not_recorded');
            $table->unsignedBigInteger('qc_by')->nullable();
            $table->timestamp('qc_at')->nullable();
            $table->text('qc_notes')->nullable();
            $table->unsignedSmallInteger('print_count')->default(0);
            $table->unsignedBigInteger('printed_by')->nullable();
            $table->uuid('current_print_batch_id')->nullable();
            $table->index(['qc_status'], 'id_cards_qc_status_idx');
        });
        DB::table('id_cards')->whereNotNull('printed_at')->update(['print_count' => 1]);
        DB::table('id_cards')->where('status', 'printed')->update(['qc_status' => 'pending']);
        Schema::table('id_cards', function (Blueprint $table): void {
            $table->foreign('card_template_version_id', 'id_cards_template_version_foreign')->references('id')->on('card_template_versions')->restrictOnDelete();
            $table->foreign('current_print_batch_id', 'id_cards_print_batch_foreign')->references('id')->on('card_print_batches')->nullOnDelete();
            $table->foreign('qc_by', 'id_cards_qc_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('printed_by', 'id_cards_printed_by_foreign')->references('id')->on('users')->nullOnDelete();
        });

        // The standard layout, active from the start, so printing works before anyone designs a template.
        $now = now();
        $template = (string) Str::uuid7();
        DB::table('card_templates')->insert(['id' => $template, 'code' => 'STANDARD', 'name_en' => 'Standard employee card', 'name_am' => 'መደበኛ የሠራተኛ መታወቂያ', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('card_template_versions')->insert(['id' => (string) Str::uuid7(), 'card_template_id' => $template, 'version' => 1, 'status' => 'active',
            'config' => json_encode(CardTemplateConfig::defaults()), 'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('id_cards', function (Blueprint $table): void {
            $table->dropForeign('id_cards_template_version_foreign');
            $table->dropForeign('id_cards_print_batch_foreign');
            $table->dropForeign('id_cards_qc_by_foreign');
            $table->dropForeign('id_cards_printed_by_foreign');
            $table->dropIndex('id_cards_qc_status_idx');
            $table->dropColumn(['card_template_version_id', 'qc_status', 'qc_by', 'qc_at', 'qc_notes', 'print_count', 'printed_by', 'current_print_batch_id']);
        });
        Schema::dropIfExists('card_print_batch_items');
        Schema::dropIfExists('card_print_batches');
        Schema::dropIfExists('card_template_versions');
        Schema::dropIfExists('card_templates');
    }
};
