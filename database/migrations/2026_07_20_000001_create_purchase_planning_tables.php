<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 14: planificación histórica de compras y proveedores.
     *
     * Catálogo (purchase_categories, purchase_products, suppliers) es
     * reutilizable entre ediciones y NO está atado a un año ni a un equipo:
     * el equipo se identifica siempre por el slug 'compras' vía las rutas
     * (mismo patrón que team_tasks/schedule_activities), nunca por un ID de
     * equipo hardcodeado — no existe una tabla "teams" en este proyecto.
     *
     * La planificación real por edición vive en purchase_plan_items, con
     * year_id propio. Las cantidades de referencia para 1000/1500 porciones
     * se guardan EN CADA ITEM POR AÑO (no en el producto), precisamente para
     * preservar cómo se planificó cada edición sin sobrescribir el historial.
     */
    public function up(): void
    {
        Schema::create('purchase_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_category_id')->nullable()->constrained('purchase_categories')->nullOnDelete();
            $table->string('name')->unique();
            $table->string('unit', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('year_id')->constrained('years')->cascadeOnDelete();
            $table->foreignId('purchase_product_id')->constrained('purchase_products')->restrictOnDelete();

            // Previsto
            $table->decimal('qty_1000', 10, 3)->nullable();
            $table->decimal('qty_1500', 10, 3)->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('estimated_total_price', 12, 2)->nullable();
            $table->foreignId('planned_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            // Real
            $table->decimal('actual_quantity', 10, 3)->nullable();
            $table->decimal('actual_total_price', 12, 2)->nullable();
            $table->foreignId('actual_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['year_id', 'purchase_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_plan_items');
        Schema::dropIfExists('purchase_products');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('purchase_categories');
    }
};
