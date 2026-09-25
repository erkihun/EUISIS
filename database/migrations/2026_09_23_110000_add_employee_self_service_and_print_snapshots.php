<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();
            $table->boolean('employee_link_locked')->default(false);
        });
        // Preserve only unambiguous existing associations. Never guess from names.
        DB::table('users')->select('id', 'email')->orderBy('id')->chunk(200, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['employee_link_locked' => true]);
                $ids = DB::table('employees')->where('email', $user->email)->pluck('id');
                if ($ids->count() === 1) {
                    DB::table('users')->where('id', $user->id)->update(['employee_id' => $ids->first()]);
                }
            }
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->string('emergency_contact_relationship')->nullable();
            $table->string('preferred_language', 2)->default('en');
            $table->json('notification_preferences')->nullable();
        });
        Schema::table('id_cards', function (Blueprint $table) {
            $table->boolean('reprint_required')->default(false)->index();
            $table->json('reprint_reasons')->nullable();
            $table->timestamp('reprint_required_at')->nullable();
        });
        Schema::table('id_card_templates', function (Blueprint $table) {
            $table->json('employee_fields')->nullable();
        });
        Schema::create('id_card_print_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('id_card_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $table->uuid('template_id')->nullable();
            $table->string('template_version', 64);
            $table->string('orientation', 12);
            $table->decimal('width_mm', 7, 2);
            $table->decimal('height_mm', 7, 2);
            $table->json('rendered_fields');
            $table->text('rendered_values');
            $table->text('comparison_values');
            $table->string('artifact_path');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable()->index();
            $table->unsignedInteger('print_sequence')->nullable();
            $table->unique(['id_card_id', 'print_sequence']);
            $table->timestamps();
        });
        Schema::create('employee_contact_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('field', 10);
            $table->text('value');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_correction_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('field');
            $table->text('requested_value');
            $table->string('status')->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_correction_requests');
        Schema::dropIfExists('employee_contact_verifications');
        Schema::dropIfExists('id_card_print_snapshots');
        Schema::table('id_card_templates', fn (Blueprint $table) => $table->dropColumn('employee_fields'));
        Schema::table('id_cards', fn (Blueprint $table) => $table->dropColumn(['reprint_required', 'reprint_reasons', 'reprint_required_at']));
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['emergency_contact_relationship', 'preferred_language', 'notification_preferences']));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('employee_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('employee_link_locked'));
    }
};
