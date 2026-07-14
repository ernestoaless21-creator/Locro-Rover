<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 5C (rediseño de alta/edición de pedidos): dirección de entrega
     * usada para ESTE pedido puntual cuando es delivery (take_away = false).
     *
     * Se guarda como una INSTANTANEA propia del pedido, independiente de
     * clients.address (la dirección permanente del cliente). Un cliente puede
     * pedir delivery a una dirección distinta sin que eso sobrescriba
     * automáticamente su dirección habitual (ver PROJECT_CONTEXT.md, seccion F).
     *
     * take_away ya existe desde la migracion 2026_01_01_000009 y se reutiliza
     * tal cual (true = retira en mano, false = delivery): no hace falta una
     * columna nueva solo para invertir la semántica visual del checkbox, eso
     * se resuelve enteramente en el frontend (New.vue/Edit.vue).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_address')->nullable()->after('take_away');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_address');
        });
    }
};
