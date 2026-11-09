<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The print station: saved printer profiles, and each batch card tied to the
 * frozen snapshot that was sent to the printer, so a bulk run keeps the same
 * "what printed is what was recorded" trail as a single card.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every step is guarded: MySQL DDL is not transactional, so a run that
        // fails halfway must be able to resume where it stopped.
        if (! Schema::hasTable('card_printer_profiles')) {
            $this->createProfiles();
        }

        $this->addForeignColumn('card_print_batches', 'printer_profile_id', 'card_printer_profiles');
        $this->addForeignColumn('card_print_batch_items', 'id_card_print_snapshot_id', 'id_card_print_snapshots');
        if (! Schema::hasColumn('card_print_batch_items', 'printed_at')) {
            Schema::table('card_print_batch_items', function (Blueprint $table): void {
                $table->timestamp('printed_at')->nullable();
            });
        }
    }

    /** A nullable uuid column and its SET NULL foreign key, each added only if missing. */
    private function addForeignColumn(string $table, string $column, string $references): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->uuid($column)->nullable();
            });
        }
        $hasKey = collect(Schema::getForeignKeys($table))->contains(fn (array $key): bool => $key['columns'] === [$column]);
        if (! $hasKey) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $references): void {
                $blueprint->foreign($column)->references('id')->on($references)->nullOnDelete();
            });
        }
    }

    private function createProfiles(): void
    {
        Schema::create('card_printer_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            // `card`: a direct-to-card printer, one card face per page.
            // `sheet`: a desktop printer, several cards per A4/Letter page.
            $table->string('mode', 10)->default('card');
            $table->string('paper', 10)->nullable();
            // Which faces print, and whether a duplex printer gets them as
            // front/back pairs (`interleaved`) or all fronts then all backs.
            $table->string('sides', 10)->default('both');
            $table->string('duplex_order', 12)->default('interleaved');
            // Calibration: printers rarely land at exactly 0,0 or 100%.
            $table->decimal('offset_x_mm', 5, 2)->default(0);
            $table->decimal('offset_y_mm', 5, 2)->default(0);
            $table->unsignedSmallInteger('scale_percent')->default(100);
            $table->decimal('margin_mm', 5, 2)->default(6);
            $table->decimal('gap_mm', 5, 2)->default(3);
            $table->boolean('cut_guides')->default(true);
            // Sheet duplex on the long edge reverses columns on the back page.
            $table->boolean('mirror_backs')->default(true);
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('card_print_batch_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('id_card_print_snapshot_id');
            $table->dropColumn('printed_at');
        });
        Schema::table('card_print_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('printer_profile_id');
        });
        Schema::dropIfExists('card_printer_profiles');
    }
};
