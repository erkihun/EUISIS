<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letters (ADR 0037 §5, owner's rule of 2026-11-09): inside one institution,
 * Amharic and English letters are numbered separately. Sequences are always
 * kept per language; a numbering rule may name a language or apply to both.
 *
 * The new unique index is created before the old one is dropped: on MySQL the
 * organization foreign key leans on the old index and refuses to lose it
 * while no other index starts with organization_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_numbering_rules', function (Blueprint $table): void {
            $table->string('language', 2)->nullable()->after('direction');
            $table->string('language_code', 12)->nullable()->after('direction_code');
        });
        Schema::table('letter_numbering_rules', function (Blueprint $table): void {
            $table->unique(['organization_id', 'direction', 'language'], 'ltr_num_org_dir_lang_uq');
        });
        Schema::table('letter_numbering_rules', function (Blueprint $table): void {
            $table->dropUnique('ltr_num_org_direction_uq');
        });

        Schema::table('letter_sequences', function (Blueprint $table): void {
            $table->string('language', 2)->default('am')->after('direction');
        });
        Schema::table('letter_sequences', function (Blueprint $table): void {
            $table->unique(['organization_id', 'direction', 'language', 'period_key'], 'ltr_seq_org_dir_lang_period_uq');
        });
        Schema::table('letter_sequences', function (Blueprint $table): void {
            $table->dropUnique('ltr_seq_org_dir_period_uq');
        });
    }

    public function down(): void
    {
        Schema::table('letter_sequences', function (Blueprint $table): void {
            $table->unique(['organization_id', 'direction', 'period_key'], 'ltr_seq_org_dir_period_uq');
        });
        Schema::table('letter_sequences', function (Blueprint $table): void {
            $table->dropUnique('ltr_seq_org_dir_lang_period_uq');
            $table->dropColumn('language');
        });

        Schema::table('letter_numbering_rules', function (Blueprint $table): void {
            $table->unique(['organization_id', 'direction'], 'ltr_num_org_direction_uq');
        });
        Schema::table('letter_numbering_rules', function (Blueprint $table): void {
            $table->dropUnique('ltr_num_org_dir_lang_uq');
            $table->dropColumn(['language', 'language_code']);
        });
    }
};
