<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letters, third cut (docs/letters-research.md §9): the letterhead's footer,
 * contacts placement and official stamp; reply-time standards per
 * institution (default due dates by priority, interim-reply window,
 * escalation); a controlled file classification scheme per institution; and
 * the recipient's email for dispatch outside the platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_letterheads', function (Blueprint $table): void {
            $table->string('footer_am', 300)->nullable()->after('motto_en');
            $table->string('footer_en', 300)->nullable()->after('footer_am');
            $table->string('contacts_position', 8)->default('header')->after('footer_en');
            $table->boolean('show_page_numbers')->default(true)->after('contacts_position');
            $table->string('stamp_path', 255)->nullable()->after('logo_right_path');
        });

        if (! Schema::hasTable('letter_settings')) {
            Schema::create('letter_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('organization_id')->nullable()->constrained('organizations', 'id', 'ltr_set_org_fk')->cascadeOnDelete();
                $table->unsignedSmallInteger('reply_days_normal')->default(10);
                $table->unsignedSmallInteger('reply_days_urgent')->default(3);
                $table->unsignedSmallInteger('reply_days_very_urgent')->default(1);
                $table->unsignedSmallInteger('interim_reply_days')->default(2);
                $table->unsignedSmallInteger('escalation_after_days')->default(2);
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'ltr_set_updated_by_fk')->nullOnDelete();
                $table->timestamps();
                $table->unique('organization_id', 'ltr_set_org_uq');
            });
        }

        if (! Schema::hasTable('letter_file_classes')) {
            Schema::create('letter_file_classes', function (Blueprint $table): void {
                $table->uuid('id');
                $table->primary('id');
                $table->foreignUuid('organization_id')->nullable()->constrained('organizations', 'id', 'ltr_fc_org_fk')->cascadeOnDelete();
                $table->foreignUuid('parent_id')->nullable()->constrained('letter_file_classes', 'id', 'ltr_fc_parent_fk')->nullOnDelete();
                $table->string('code', 40);
                $table->string('title_am', 200)->nullable();
                $table->string('title_en', 200)->nullable();
                $table->boolean('active')->default(true);
                $table->unsignedSmallInteger('position')->default(0);
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'ltr_fc_updated_by_fk')->nullOnDelete();
                $table->timestamps();
                $table->unique(['organization_id', 'code'], 'ltr_fc_org_code_uq');
            });
        }

        Schema::table('letters', function (Blueprint $table): void {
            $table->string('external_party_email', 160)->nullable()->after('external_party_address');
            $table->foreignUuid('file_class_id')->nullable()->after('file_code')->constrained('letter_file_classes', 'id', 'ltr_file_class_fk')->nullOnDelete();
        });

        Schema::table('letter_registrations', function (Blueprint $table): void {
            $table->dateTime('escalated_at')->nullable()->after('reminded_at');
            $table->dateTime('interim_reminded_at')->nullable()->after('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('letter_registrations', fn (Blueprint $table) => $table->dropColumn(['escalated_at', 'interim_reminded_at']));
        Schema::table('letters', function (Blueprint $table): void {
            $table->dropForeign('ltr_file_class_fk');
            $table->dropColumn(['external_party_email', 'file_class_id']);
        });
        Schema::dropIfExists('letter_file_classes');
        Schema::dropIfExists('letter_settings');
        Schema::table('letter_letterheads', fn (Blueprint $table) => $table->dropColumn(['footer_am', 'footer_en', 'contacts_position', 'show_page_numbers', 'stamp_path']));
    }
};
