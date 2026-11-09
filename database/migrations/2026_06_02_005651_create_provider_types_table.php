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
        if (Schema::hasTable('provider_types')) {
            return;
        }

        Schema::create('provider_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();

        foreach ([
            ['CAFETERIA', 'Cafeteria', 10],
            ['TRANSPORT', 'Transport', 20],
            ['HEALTH', 'Health', 30],
            ['CONSUMER_ASSOCIATION', 'Consumer Association', 40],
            ['INSURANCE', 'Insurance', 50],
            ['TRAINING', 'Training', 60],
            ['OTHER', 'Other', 100],
        ] as [$code, $name, $sortOrder]) {
            DB::table('provider_types')->insert([
                'id' => (string) Str::uuid7(),
                'code' => $code,
                'name_en' => $name,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_types');
    }
};
