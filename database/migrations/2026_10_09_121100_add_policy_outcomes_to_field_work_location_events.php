<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_work_location_events', function (Blueprint $table): void {
            $table->json('policy_snapshot')->nullable()->after('validation_reason');
            $table->json('validation_flags')->nullable()->after('policy_snapshot');
            $table->string('review_state', 32)->default('not_required')->after('validation_flags');
            $table->index(['field_work_request_id', 'review_state'], 'fwle_request_review_state_idx');
        });
    }

    public function down(): void
    {
        Schema::table('field_work_location_events', function (Blueprint $table): void {
            $table->dropIndex('fwle_request_review_state_idx');
            $table->dropColumn(['policy_snapshot', 'validation_flags', 'review_state']);
        });
    }
};
