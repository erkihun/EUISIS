<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('name_en')->nullable()->after('full_name')->index();
        });

        DB::table('employees')
            ->select(['id', 'metadata'])
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunk(200, function ($employees): void {
                foreach ($employees as $employee) {
                    $metadata = json_decode((string) $employee->metadata, true);
                    $nameEn = is_array($metadata) ? trim((string) ($metadata['name_en'] ?? '')) : '';

                    if ($nameEn !== '') {
                        DB::table('employees')->where('id', $employee->id)->update(['name_en' => $nameEn]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('name_en');
        });
    }
};
