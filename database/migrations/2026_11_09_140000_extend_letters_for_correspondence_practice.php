<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letters, second cut (docs/letters-research.md): what correspondence
 * registries worldwide record and ours did not yet — a security
 * classification, the institution's file code, copy recipients (ግልባጭ),
 * how and under which tracking reference a letter was dispatched, the
 * recipient's acknowledgement of receipt, the physical location of the paper,
 * a verification code for the printed letter, and reminder bookkeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letters', function (Blueprint $table): void {
            $table->string('classification', 12)->default('internal')->after('priority');
            $table->string('file_code', 60)->nullable()->after('classification');
            $table->string('dispatch_mode', 12)->nullable()->after('dispatched_at');
            $table->string('dispatch_reference', 120)->nullable()->after('dispatch_mode');
            $table->string('dispatch_note', 500)->nullable()->after('dispatch_reference');
            $table->string('verification_code', 16)->nullable()->after('dispatch_note');
            $table->unique('verification_code', 'ltr_verification_code_uq');
            $table->index(['organization_id', 'file_code'], 'ltr_org_file_code_idx');
        });

        Schema::table('letter_registrations', function (Blueprint $table): void {
            $table->string('physical_location', 120)->nullable()->after('due_on');
            $table->dateTime('acknowledged_at')->nullable()->after('physical_location');
            $table->string('acknowledgement_note', 500)->nullable()->after('acknowledged_at');
            $table->dateTime('reminded_at')->nullable()->after('acknowledgement_note');
        });

        Schema::table('letter_routings', function (Blueprint $table): void {
            $table->dateTime('reminded_at')->nullable()->after('outcome');
        });

        if (! Schema::hasTable('letter_recipients')) {
            Schema::create('letter_recipients', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('letter_id')->constrained('letters', 'id', 'ltr_rcp_letter_fk')->cascadeOnDelete();
                $table->string('kind', 4)->default('cc');
                $table->foreignUuid('organization_id')->nullable()->constrained('organizations', 'id', 'ltr_rcp_org_fk')->nullOnDelete();
                $table->string('external_name', 255)->nullable();
                $table->string('external_address', 500)->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
                $table->index(['letter_id', 'kind'], 'ltr_rcp_letter_kind_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_recipients');
        Schema::table('letter_routings', fn (Blueprint $table) => $table->dropColumn('reminded_at'));
        Schema::table('letter_registrations', fn (Blueprint $table) => $table->dropColumn(['physical_location', 'acknowledged_at', 'acknowledgement_note', 'reminded_at']));
        Schema::table('letters', function (Blueprint $table): void {
            $table->dropUnique('ltr_verification_code_uq');
            $table->dropIndex('ltr_org_file_code_idx');
            $table->dropColumn(['classification', 'file_code', 'dispatch_mode', 'dispatch_reference', 'dispatch_note', 'verification_code']);
        });
    }
};
