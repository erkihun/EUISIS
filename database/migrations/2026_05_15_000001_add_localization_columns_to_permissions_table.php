<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->string('label_en', 100)->nullable()->after('guard_name');
            $table->string('label_am', 100)->nullable()->after('label_en');
            $table->text('description_en')->nullable()->after('label_am');
            $table->text('description_am')->nullable()->after('description_en');
            $table->string('group', 50)->nullable()->after('description_am')->index();
            $table->integer('sort_order')->default(0)->after('group');
            $table->boolean('is_system')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->dropIndex(['group']);
            $table->dropColumn(['label_en', 'label_am', 'description_en', 'description_am', 'group', 'sort_order', 'is_system']);
        });
    }
};
