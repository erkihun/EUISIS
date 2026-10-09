<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->string('job_position_code')->nullable()->after('organization_id');
            $table->text('description_en')->nullable()->after('title_am');
            $table->text('description_am')->nullable()->after('description_en');
            $table->string('grade_level')->nullable()->after('description_am');
            $table->string('job_family')->nullable()->after('grade_level');
            $table->date('effective_from')->nullable()->after('is_active');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->json('metadata')->nullable()->after('effective_to');
        });

        DB::table('positions')
            ->orderBy('created_at')
            ->get(['id', 'code', 'title_en'])
            ->each(function (object $position): void {
                $seedCode = $position->code
                    ?: 'POS-'.Str::upper(Str::random(8));

                DB::table('positions')
                    ->where('id', $position->id)
                    ->update([
                        'job_position_code' => $seedCode,
                        'effective_from' => now()->toDateString(),
                    ]);
            });

        Schema::table('positions', function (Blueprint $table): void {
            $table->string('job_position_code')->nullable(false)->change();
            $table->unique('job_position_code');
            $table->index(['organization_id', 'is_active']);
            $table->index(['job_family', 'grade_level']);
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropUnique(['job_position_code']);
            $table->dropIndex(['organization_id', 'is_active']);
            $table->dropIndex(['job_family', 'grade_level']);
            $table->dropColumn([
                'job_position_code',
                'description_en',
                'description_am',
                'grade_level',
                'job_family',
                'effective_from',
                'effective_to',
                'metadata',
            ]);
        });
    }
};
