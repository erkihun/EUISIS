<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letters: the institutional letterhead printed on every composed letter
 * (docs/letters-research.md §9, first recommendation): emblems, the
 * institution's names in both languages, its parent body, address and
 * contacts, and a motto. A row with a null organization is the platform
 * default every institution without its own letterhead falls back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letter_letterheads')) {
            return;
        }
        Schema::create('letter_letterheads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations', 'id', 'ltr_lh_org_fk')->cascadeOnDelete();
            $table->string('name_am', 200)->nullable();
            $table->string('name_en', 200)->nullable();
            $table->string('parent_am', 200)->nullable();
            $table->string('parent_en', 200)->nullable();
            $table->string('address_am', 300)->nullable();
            $table->string('address_en', 300)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('fax', 60)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('website', 120)->nullable();
            $table->string('po_box', 40)->nullable();
            $table->string('motto_am', 200)->nullable();
            $table->string('motto_en', 200)->nullable();
            $table->string('logo_left_path', 255)->nullable();
            $table->string('logo_right_path', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'ltr_lh_updated_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique('organization_id', 'ltr_lh_org_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_letterheads');
    }
};
