<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core settings: one row per changed value. A setting without a row has its
 * default from App\Settings\CoreSettingsRegistry, so a new installation needs
 * no seeding and a default can be changed in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 64);
            $table->string('key', 96);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_settings');
    }
};
