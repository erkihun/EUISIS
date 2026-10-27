<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Where the EUISIS mirror sync stands (cafeteria:sync-from-euisis). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('euisis_sync_state', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('euisis_sync_state');
    }
};
