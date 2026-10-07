<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cafeteria_menus')) {
            Schema::create('cafeteria_menus', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('cafeteria_provider_id')->constrained('cafeteria_providers')->cascadeOnDelete();
                $table->date('menu_date')->index();
                $table->string('title_en');
                $table->string('title_am')->nullable();
                $table->text('description_en')->nullable();
                $table->text('description_am')->nullable();
                $table->string('meal_type')->default('lunch')->index();
                $table->decimal('price', 12, 2)->nullable();
                $table->boolean('subsidy_eligible')->default(true);
                $table->unsignedInteger('max_orders')->nullable();
                $table->timestamp('order_cutoff_at')->nullable();
                $table->string('status')->default('draft')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();

                $table->index(['cafeteria_provider_id', 'menu_date', 'meal_type', 'status'], 'caf_menus_prov_date_meal_idx');
            });
        }

        if (! Schema::hasTable('cafeteria_menu_items')) {
            Schema::create('cafeteria_menu_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('cafeteria_menu_id')->constrained('cafeteria_menus')->cascadeOnDelete();
                $table->string('name_en');
                $table->string('name_am')->nullable();
                $table->text('description_en')->nullable();
                $table->text('description_am')->nullable();
                $table->string('item_type')->nullable();
                $table->boolean('is_available')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cafeteria_food_orders')) {
            Schema::create('cafeteria_food_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('order_number')->unique();
                $table->foreignUuid('cafeteria_provider_id')->constrained('cafeteria_providers')->cascadeOnDelete();
                $table->foreignUuid('cafeteria_menu_id')->constrained('cafeteria_menus')->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignUuid('id_card_id')->nullable()->constrained('id_cards')->nullOnDelete();
                $table->foreignUuid('fulfilled_transaction_id')->nullable()->constrained('cafeteria_transactions')->nullOnDelete();
                $table->date('order_date')->index();
                $table->dateTime('ordered_at');
                $table->timestamp('served_at')->nullable();
                $table->string('fulfillment_nonce', 64)->nullable()->unique();
                $table->string('status')->default('pending')->index();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->decimal('subsidy_amount_applied', 12, 2)->default(0);
                $table->decimal('employee_payable_amount', 12, 2)->default(0);
                $table->text('notes')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['cafeteria_provider_id', 'order_date'], 'cfo_provider_date_idx');
                $table->index(['employee_id', 'order_date'], 'cfo_employee_date_idx');
            });
        }

        if (! Schema::hasTable('cafeteria_food_order_items')) {
            Schema::create('cafeteria_food_order_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('cafeteria_food_order_id')->constrained('cafeteria_food_orders')->cascadeOnDelete();
                $table->foreignUuid('cafeteria_menu_item_id')->nullable()->constrained('cafeteria_menu_items')->nullOnDelete();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('total_price', 12, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_food_order_items');
        Schema::dropIfExists('cafeteria_food_orders');
        Schema::dropIfExists('cafeteria_menu_items');
        Schema::dropIfExists('cafeteria_menus');
    }
};
