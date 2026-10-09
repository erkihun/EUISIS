<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_work_location_events', function (Blueprint $table): void {
            $table->dropUnique('fwle_one_event_per_participant_type');
        });

        DB::statement(<<<'SQL'
            create unique index fwle_one_accepted_event_per_participant_type
            on field_work_location_events (field_work_request_id, field_work_participant_id, event_type)
            where review_state <> 'blocked'
            SQL);
    }

    public function down(): void
    {
        DB::statement('drop index if exists fwle_one_accepted_event_per_participant_type');

        $hasDuplicates = DB::table('field_work_location_events')
            ->select(['field_work_request_id', 'field_work_participant_id', 'event_type'])
            ->groupBy(['field_work_request_id', 'field_work_participant_id', 'event_type'])
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException('Cannot restore the legacy location-event constraint without deleting immutable blocked retry evidence.');
        }

        Schema::table('field_work_location_events', function (Blueprint $table): void {
            $table->unique(['field_work_request_id', 'field_work_participant_id', 'event_type'], 'fwle_one_event_per_participant_type');
        });
    }
};
