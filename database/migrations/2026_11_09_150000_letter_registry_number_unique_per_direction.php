<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letters: the incoming register and the outgoing register are separate books
 * (docs/letters-research.md §2), so an institution's incoming number 1 and
 * outgoing number 1 coexist. The registry number is unique per institution
 * AND direction. The new index is created before the old one is dropped (a
 * MySQL foreign key may lean on the old index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_registrations', function (Blueprint $table): void {
            $table->unique(['organization_id', 'direction', 'registry_number'], 'ltr_reg_org_dir_number_uq');
        });
        Schema::table('letter_registrations', function (Blueprint $table): void {
            $table->dropUnique('ltr_reg_org_number_uq');
        });
    }

    public function down(): void
    {
        Schema::table('letter_registrations', function (Blueprint $table): void {
            $table->unique(['organization_id', 'registry_number'], 'ltr_reg_org_number_uq');
        });
        Schema::table('letter_registrations', function (Blueprint $table): void {
            $table->dropUnique('ltr_reg_org_dir_number_uq');
        });
    }
};
