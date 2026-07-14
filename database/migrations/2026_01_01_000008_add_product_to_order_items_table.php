<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajuste segun datos reales: la app anterior vendia, ademas de "locro" (portions),
     * productos secundarios como "batata" y "sauces" (salsas), como cantidades aparte.
     * En el nuevo modelo cada uno es una linea de pedido (order_item) con su propio
     * "product", en vez de columnas fijas en la tabla orders. Esto tambien permite que
     * en anios "pastelitosEvent" el producto principal cambie sin tocar el esquema.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product')->default('locro')->after('order_id'); // locro | batata | salsas | pastelitos | otro
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('product');
        });
    }
};
